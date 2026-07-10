import { access, readFile } from "node:fs/promises";
import { constants } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, "..");

const requiredRootFiles = [
  "package.json",
  "README.md",
  "tsconfig.base.json",
  "tsconfig.json"
];

const requiredPackages = [
  { name: "@access402/core", dir: "packages/core" },
  { name: "@access402/express", dir: "packages/express" },
  { name: "@access402/fetch", dir: "packages/fetch" },
  { name: "@access402/storage-memory", dir: "packages/storage-memory" },
  { name: "@access402/storage-redis", dir: "packages/storage-redis" },
  { name: "@access402/testing", dir: "packages/testing" }
];

async function ensureReadable(relativePath) {
  await access(path.join(repoRoot, relativePath), constants.R_OK);
}

async function readJson(relativePath) {
  const contents = await readFile(path.join(repoRoot, relativePath), "utf8");
  return JSON.parse(contents);
}

async function verifyRoot() {
  for (const relativePath of requiredRootFiles) {
    await ensureReadable(relativePath);
  }
}

async function verifyPackage(pkg) {
  const packageJsonPath = path.join(pkg.dir, "package.json");
  const tsconfigPath = path.join(pkg.dir, "tsconfig.json");
  const entryPath = path.join(pkg.dir, "src/index.ts");

  await ensureReadable(packageJsonPath);
  await ensureReadable(tsconfigPath);
  await ensureReadable(entryPath);

  const manifest = await readJson(packageJsonPath);

  if (manifest.name !== pkg.name) {
    throw new Error(
      `Expected ${packageJsonPath} to declare ${pkg.name}, found ${manifest.name ?? "nothing"}.`
    );
  }

  if (manifest.main !== "./dist/index.js") {
    throw new Error(`Expected ${packageJsonPath} to point main at ./dist/index.js.`);
  }

  if (manifest.types !== "./dist/index.d.ts") {
    throw new Error(`Expected ${packageJsonPath} to point types at ./dist/index.d.ts.`);
  }
}

async function main() {
  const scenario = process.argv[2];

  await verifyRoot();
  await Promise.all(requiredPackages.map(verifyPackage));

  console.log("Access402 workspace skeleton looks structurally sound.");

  if (scenario) {
    console.log(
      `Scenario '${scenario}' is not implemented yet; the current smoke test only validates repo structure.`
    );
  }
}

main().catch((error) => {
  console.error("Structural smoke test failed.");
  console.error(error instanceof Error ? error.message : error);
  process.exitCode = 1;
});
