import { decodePaymentResponseHeader, wrapFetchWithPayment, x402Client } from "@x402/fetch";
import { registerExactEvmScheme } from "@x402/evm/exact/client";
import { EthereumProvider } from "@walletconnect/ethereum-provider";
import { createWalletClient, custom } from "viem";
import { base, baseSepolia } from "viem/chains";

const boot = window.access402Checkout || {};
const strings = boot.strings || {};
const root = document;
const walletsEl = root.getElementById("access402-wallets");
const payButton = root.getElementById("access402-pay-button");
const reloadButton = root.getElementById("access402-reload-button");
const statusEl = root.getElementById("access402-status");
const connectedEl = root.getElementById("access402-connected");

const state = {
  providers: [],
  selectedProvider: null,
  selectedId: "",
  selectedInfo: null,
  account: "",
  busy: false,
  walletConnectProvider: null,
};

function setStatus(message, tone = "") {
  if (!statusEl) {
    return;
  }

  statusEl.textContent = String(message || "");

  if (tone) {
    statusEl.dataset.tone = tone;
    return;
  }

  delete statusEl.dataset.tone;
}

function shortAddress(address) {
  const value = String(address || "").trim();

  if (value.length < 12) {
    return value;
  }

  return `${value.slice(0, 6)}...${value.slice(-4)}`;
}

function chainHexFromCaip(networkId) {
  const match = String(networkId || "").match(/^eip155:(\d+)$/);

  if (!match) {
    return "";
  }

  return `0x${Number(match[1]).toString(16)}`;
}

function chainIdFromCaip(networkId) {
  const match = String(networkId || "").match(/^eip155:(\d+)$/);

  if (!match) {
    return 0;
  }

  return Number(match[1]);
}

function networkConfigFromCaip(networkId) {
  const value = String(networkId || "").trim();

  if (value === "eip155:84532") {
    return {
      chainId: "0x14a34",
      chainName: "Base Sepolia",
      nativeCurrency: {
        name: "Ether",
        symbol: "ETH",
        decimals: 18,
      },
      rpcUrls: ["https://sepolia.base.org"],
      blockExplorerUrls: ["https://sepolia-explorer.base.org"],
    };
  }

  if (value === "eip155:8453") {
    return {
      chainId: "0x2105",
      chainName: "Base Mainnet",
      nativeCurrency: {
        name: "Ether",
        symbol: "ETH",
        decimals: 18,
      },
      rpcUrls: ["https://mainnet.base.org"],
      blockExplorerUrls: ["https://base.blockscout.com"],
    };
  }

  return null;
}

function viemChainFromCaip(networkId) {
  const value = String(networkId || "").trim();

  if (value === "eip155:84532") {
    return baseSepolia;
  }

  if (value === "eip155:8453") {
    return base;
  }

  return undefined;
}

function rpcMapFromCaip(networkId) {
  const chainId = chainIdFromCaip(networkId);
  const networkConfig = networkConfigFromCaip(networkId);
  const rpcUrl = networkConfig && Array.isArray(networkConfig.rpcUrls) ? String(networkConfig.rpcUrls[0] || "") : "";

  if (!chainId || !rpcUrl) {
    return undefined;
  }

  return {
    [chainId]: rpcUrl,
  };
}

function providerLabel(provider, info = {}) {
  if (info && info.name) {
    return String(info.name);
  }

  if (provider && provider.isCoinbaseWallet) {
    return "Coinbase Wallet";
  }

  if (provider && provider.isMetaMask) {
    return "MetaMask";
  }

  return "Browser Wallet";
}

function providerId(provider, info = {}) {
  if (info && info.rdns) {
    return String(info.rdns);
  }

  if (provider && provider.isCoinbaseWallet) {
    return "coinbase-wallet";
  }

  if (provider && provider.isMetaMask) {
    return "metamask";
  }

  return `provider-${state.providers.length + 1}`;
}

function addProvider(provider, info = {}) {
  if (!provider || typeof provider.request !== "function") {
    return;
  }

  const exists = state.providers.some((entry) => entry.provider === provider);

  if (exists) {
    return;
  }

  state.providers.push({
    id: providerId(provider, info),
    provider,
    connector: "injected",
    info: {
      name: providerLabel(provider, info),
      icon: info.icon || "",
      rdns: info.rdns || "",
    },
  });
}

function ensureWalletConnectOption() {
  const projectId = String(boot.walletConnectProjectId || "").trim();

  if (!projectId) {
    return;
  }

  const exists = state.providers.some((entry) => entry.id === "walletconnect");

  if (exists) {
    return;
  }

  state.providers.push({
    id: "walletconnect",
    provider: null,
    connector: "walletconnect",
    info: {
      name: "WalletConnect",
      icon: "",
      rdns: "walletconnect",
    },
  });
}

function collectInjectedProviders() {
  const injected = window.ethereum;

  if (!injected) {
    return;
  }

  if (Array.isArray(injected.providers)) {
    injected.providers.forEach((provider) => addProvider(provider));
    return;
  }

  addProvider(injected);
}

function renderProviders() {
  if (!walletsEl) {
    return;
  }

  ensureWalletConnectOption();
  walletsEl.innerHTML = "";

  if (state.providers.length === 0) {
    setStatus(strings.missingProvider || "No compatible browser wallet was detected.", "error");

    if (payButton) {
      payButton.disabled = true;
      payButton.textContent = strings.walletRequired || "Connect a wallet before paying";
    }

    return;
  }

  state.providers.forEach((entry) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "access402-wallet-button";
    button.dataset.providerId = entry.id;

    const meta = document.createElement("span");
    meta.className = "access402-wallet-meta";

    const name = document.createElement("span");
    name.className = "access402-wallet-name";
    name.textContent = entry.info.name;

    const status = document.createElement("span");
    status.className = "access402-wallet-status";
    status.textContent = state.selectedId === entry.id ? "Connected" : "Connect";

    meta.appendChild(name);
    meta.appendChild(status);
    button.appendChild(meta);

    button.addEventListener("click", async () => {
      await connectProvider(entry);
    });

    walletsEl.appendChild(button);
  });
}

function renderConnected() {
  if (!connectedEl) {
    return;
  }

  if (!state.account || !state.selectedInfo) {
    connectedEl.hidden = true;
    connectedEl.textContent = "";
    return;
  }

  connectedEl.hidden = false;
  connectedEl.textContent = `${state.selectedInfo.name} connected as ${shortAddress(state.account)}`;
}

function renderPayButton() {
  if (!payButton) {
    return;
  }

  const price = String(boot.payment && boot.payment.price ? boot.payment.price : "").trim();
  const currency = String(boot.payment && boot.payment.currency ? boot.payment.currency : "").trim();
  const amount = [price, currency].filter(Boolean).join(" ");
  const network = String(boot.payment && boot.payment.networkLabel ? boot.payment.networkLabel : "").trim();

  if (state.busy) {
    payButton.disabled = true;
    payButton.textContent = strings.creatingPayment || "Creating payment…";
    return;
  }

  if (!state.account) {
    payButton.disabled = true;
    payButton.textContent = strings.walletRequired || "Connect a wallet before paying";
    return;
  }

  payButton.disabled = false;
  payButton.textContent = amount ? `Pay ${amount}${network ? ` on ${network}` : ""}` : "Pay and unlock";
}

function render() {
  renderProviders();
  renderConnected();
  renderPayButton();
}

async function ensureRequiredNetwork(provider) {
  const requiredHex = chainHexFromCaip(boot.payment && boot.payment.networkId ? boot.payment.networkId : "");
  const networkConfig = networkConfigFromCaip(boot.payment && boot.payment.networkId ? boot.payment.networkId : "");

  if (!requiredHex) {
    return;
  }

  const currentChain = await provider.request({ method: "eth_chainId" });

  if (String(currentChain).toLowerCase() === requiredHex.toLowerCase()) {
    return;
  }

  setStatus(strings.switchingChain || "Switching your wallet to the required network…");

  try {
    await provider.request({
      method: "wallet_switchEthereumChain",
      params: [{ chainId: requiredHex }],
    });
  } catch (error) {
    const code = error && typeof error === "object" && "code" in error ? Number(error.code) : 0;
    const message = error instanceof Error ? error.message : String(error || "");
    const canAddNetwork = code === 4902 || /unrecognized chain|unknown chain|not added/i.test(message);

    if (canAddNetwork && networkConfig) {
      try {
        await provider.request({
          method: "wallet_addEthereumChain",
          params: [networkConfig],
        });
        return;
      } catch (addError) {
        const addMessage = addError instanceof Error && addError.message ? addError.message : "";
        const fallback = `${strings.wrongNetwork || "This payment requires the configured network in your wallet."} ${boot.payment && boot.payment.networkLabel ? boot.payment.networkLabel : ""}`.trim();
        throw new Error(addMessage ? `${fallback} ${addMessage}` : fallback);
      }
    }

    const fallback = `${strings.wrongNetwork || "This payment requires the configured network in your wallet."} ${boot.payment && boot.payment.networkLabel ? boot.payment.networkLabel : ""}`.trim();
    const detail = message;
    throw new Error(detail ? `${fallback} ${detail}` : fallback);
  }
}

async function connectProvider(entry) {
  if (!entry || state.busy) {
    return;
  }

  if (entry.connector === "walletconnect") {
    await connectWalletConnect(entry);
    return;
  }

  try {
    state.busy = true;
    setStatus(strings.connectingWallet || "Connecting wallet…");
    renderPayButton();

    const accounts = await entry.provider.request({ method: "eth_requestAccounts" });
    const account = Array.isArray(accounts) ? String(accounts[0] || "") : "";

    if (!account) {
      throw new Error(strings.walletRequired || "Connect a wallet before paying for access.");
    }

    await ensureRequiredNetwork(entry.provider);

    state.selectedProvider = entry.provider;
    state.selectedId = entry.id;
    state.selectedInfo = entry.info;
    state.account = account;
    setStatus(`${entry.info.name} connected. Ready to pay.`, "success");
  } catch (error) {
    setStatus(error instanceof Error ? error.message : strings.genericError || "The payment could not be completed.", "error");
  } finally {
    state.busy = false;
    render();
  }
}

async function getWalletConnectProvider() {
  if (state.walletConnectProvider) {
    return state.walletConnectProvider;
  }

  const projectId = String(boot.walletConnectProjectId || "").trim();

  if (!projectId) {
    throw new Error(strings.walletConnectMissingProjectId || "WalletConnect is not configured for this site yet.");
  }

  const networkId = String(boot.payment && boot.payment.networkId ? boot.payment.networkId : "").trim();
  const chainId = chainIdFromCaip(networkId);
  const provider = await EthereumProvider.init({
    projectId,
    showQrModal: true,
    optionalChains: chainId ? [chainId] : undefined,
    rpcMap: rpcMapFromCaip(networkId),
    metadata: {
      name: String(boot.siteName || "Access402"),
      description: "Access402 wallet checkout",
      url: String(boot.siteUrl || window.location.origin),
      icons: boot.siteIcon ? [String(boot.siteIcon)] : [],
    },
  });

  provider.on("accountsChanged", (accounts) => {
    if (state.selectedId !== "walletconnect") {
      return;
    }

    const account = Array.isArray(accounts) ? String(accounts[0] || "") : "";
    state.account = account;

    if (account === "") {
      state.selectedProvider = null;
      state.selectedId = "";
      state.selectedInfo = null;
      setStatus(strings.walletDisconnected || "Wallet disconnected.");
    }

    render();
  });

  provider.on("disconnect", () => {
    if (state.selectedId !== "walletconnect") {
      return;
    }

    state.selectedProvider = null;
    state.selectedId = "";
    state.selectedInfo = null;
    state.account = "";
    setStatus(strings.walletDisconnected || "Wallet disconnected.");
    render();
  });

  state.walletConnectProvider = provider;

  return provider;
}

async function connectWalletConnect(entry) {
  try {
    state.busy = true;
    setStatus(strings.connectingWallet || "Connecting wallet…");
    renderPayButton();

    const provider = await getWalletConnectProvider();
    const accounts = await provider.enable();
    const account = Array.isArray(accounts) ? String(accounts[0] || "") : String(provider.accounts?.[0] || "");

    if (!account) {
      throw new Error(strings.walletRequired || "Connect a wallet before paying for access.");
    }

    await ensureRequiredNetwork(provider);

    state.selectedProvider = provider;
    state.selectedId = entry.id;
    state.selectedInfo = entry.info;
    state.account = account;
    setStatus(`${entry.info.name} connected. Ready to pay.`, "success");
  } catch (error) {
    setStatus(error instanceof Error ? error.message : strings.genericError || "The payment could not be completed.", "error");
  } finally {
    state.busy = false;
    render();
  }
}

function createSigner(provider, address) {
  const chain = viemChainFromCaip(boot.payment && boot.payment.networkId ? boot.payment.networkId : "");
  const walletClient = createWalletClient({
    account: address,
    chain,
    transport: custom(provider),
  });

  return {
    address,
    async signTypedData(message) {
      return walletClient.signTypedData({
        account: walletClient.account || address,
        domain: message.domain,
        types: message.types,
        primaryType: message.primaryType,
        message: message.message,
      });
    },
  };
}

async function parseJson(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

async function payForUnlock() {
  if (state.busy) {
    return;
  }

  if (!state.selectedProvider || !state.account) {
    setStatus(strings.walletRequired || "Connect a wallet before paying for access.", "error");
    return;
  }

  try {
    state.busy = true;
    renderPayButton();
    await ensureRequiredNetwork(state.selectedProvider);
    setStatus(strings.creatingPayment || "Creating and submitting the x402 payment…");

    const client = new x402Client();
    const signer = createSigner(state.selectedProvider, state.account);
    const networkId = String(boot.payment && boot.payment.networkId ? boot.payment.networkId : "").trim();
    registerExactEvmScheme(client, {
      signer,
      networks: networkId ? [networkId] : undefined,
    });

    const fetchWithPayment = wrapFetchWithPayment(window.fetch.bind(window), client);
    const response = await fetchWithPayment(String(boot.unlockEndpoint || ""), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        target_path: String(boot.target && boot.target.path ? boot.target.path : ""),
        target_url: String(boot.target && boot.target.url ? boot.target.url : ""),
      }),
    });
    const payload = await parseJson(response);

    if (!response.ok) {
      const message = payload && payload.message ? payload.message : `Payment failed with HTTP ${response.status}.`;
      throw new Error(message);
    }

    const paymentResponseHeader = response.headers.get("PAYMENT-RESPONSE");

    if (paymentResponseHeader) {
      try {
        const paymentResponse = decodePaymentResponseHeader(paymentResponseHeader);
        const tx = paymentResponse && paymentResponse.transaction ? String(paymentResponse.transaction) : "";

        if (tx) {
          setStatus(`Payment settled. Transaction ${tx}. Reloading…`, "success");
        } else {
          setStatus(strings.unlocking || "Payment settled. Unlocking the page…", "success");
        }
      } catch {
        setStatus(strings.unlocking || "Payment settled. Unlocking the page…", "success");
      }
    } else {
      setStatus(strings.unlocking || "Payment settled. Unlocking the page…", "success");
    }

    const redirectUrl = payload && payload.redirectUrl ? payload.redirectUrl : (boot.target && boot.target.url ? boot.target.url : window.location.href);
    window.setTimeout(() => {
      window.location.assign(String(redirectUrl));
    }, 220);
  } catch (error) {
    setStatus(error instanceof Error ? error.message : strings.genericError || "The payment could not be completed.", "error");
  } finally {
    state.busy = false;
    renderPayButton();
  }
}

function attachEvents() {
  if (payButton) {
    payButton.addEventListener("click", () => {
      void payForUnlock();
    });
  }

  if (reloadButton) {
    reloadButton.addEventListener("click", () => {
      window.location.reload();
    });
  }
}

function discoverProviders() {
  const announced = [];
  const listener = (event) => {
    const detail = event && event.detail ? event.detail : null;

    if (!detail || !detail.provider) {
      return;
    }

    announced.push(detail);
    addProvider(detail.provider, detail.info || {});
    render();
  };

  window.addEventListener("eip6963:announceProvider", listener);
  collectInjectedProviders();
  ensureWalletConnectOption();

  try {
    window.dispatchEvent(new Event("eip6963:requestProvider"));
  } catch {
  }

  window.setTimeout(() => {
    window.removeEventListener("eip6963:announceProvider", listener);

    if (announced.length === 0) {
      collectInjectedProviders();
    }

    ensureWalletConnectOption();
    render();
  }, 220);
}

attachEvents();
render();
discoverProviders();
