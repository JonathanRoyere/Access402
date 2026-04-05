(function () {
    const app = window.access402Admin;

    if (!app) {
        return;
    }

    const root = document.querySelector('.access402-admin');

    if (!root) {
        return;
    }

    const panels = Array.from(document.querySelectorAll('[data-panel]'));
    const backdrop = document.querySelector('[data-panel-backdrop]');

    const qs = (selector, context = document) => context.querySelector(selector);
    const qsa = (selector, context = document) => Array.from(context.querySelectorAll(selector));

    const renderNotice = (message, type = 'success') => {
        const existing = qs('.access402-runtime-notice', root);

        if (existing) {
            existing.remove();
        }

        const notice = document.createElement('div');
        notice.className = `notice ${type === 'error' ? 'notice-error' : 'notice-success'} access402-runtime-notice`;
        notice.innerHTML = `<p>${message}</p>`;
        root.prepend(notice);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const buildFormData = (action, payload) => {
        const data = new FormData();
        data.append('action', action);
        data.append('nonce', app.nonce);

        if (payload instanceof FormData) {
            payload.forEach((value, key) => data.append(key, value));
            return data;
        }

        Object.entries(payload || {}).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => data.append(`${key}[]`, item));
                return;
            }

            data.append(key, value);
        });

        return data;
    };

    const post = async (action, payload) => {
        const response = await fetch(app.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: buildFormData(action, payload),
        });
        const json = await response.json();

        if (!json.success) {
            throw new Error(json.data?.message || 'Request failed.');
        }

        return json.data;
    };

    const openPanel = (name) => {
        const panel = qs(`[data-panel="${name}"]`);

        if (!panel) {
            return;
        }

        if (backdrop) {
            backdrop.hidden = false;
        }

        panel.hidden = false;
        document.body.classList.add('access402-panel-open');

        requestAnimationFrame(() => {
            panel.classList.add('is-open');
        });
    };

    const closePanels = () => {
        panels.forEach((panel) => {
            panel.classList.remove('is-open');
            window.setTimeout(() => {
                panel.hidden = true;
            }, 180);
        });

        if (backdrop) {
            backdrop.hidden = true;
        }

        document.body.classList.remove('access402-panel-open');
    };

    if (backdrop) {
        backdrop.addEventListener('click', closePanels);
    }

    qsa('[data-close-panel]').forEach((button) => {
        button.addEventListener('click', closePanels);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closePanels();
        }
    });

    const settingsForm = qs('form[action*="access402_save_settings"]');

    if (settingsForm) {
        const currencySelect = qs('[data-default-currency]', settingsForm);
        const networkInput = qs('[data-default-network-input]', settingsForm);
        const networkDisplay = qs('[data-default-network-display]', settingsForm);
        const modeCheckbox = qs('input[name="test_mode"]', settingsForm);
        const panelByMode = Object.fromEntries(qsa('[data-mode-panel]', settingsForm).map((panel) => [panel.dataset.modePanel, panel]));

        const validateWalletField = (mode) => {
            const field = qs(`[data-wallet-field="${mode}"]`, settingsForm);
            const note = qs(`[data-wallet-note="${mode}"]`, settingsForm);
            const network = app.networkByCurrency[currencySelect.value] || 'Base';
            const config = app.walletValidation[network];

            if (!field || !note || !config) {
                return true;
            }

            note.classList.remove('is-error', 'is-success');

            if (!field.value.trim()) {
                note.textContent = '';
                return true;
            }

            const regex = new RegExp(config.pattern, config.flags || 'u');
            const valid = regex.test(field.value.trim());

            note.textContent = valid ? `Matches ${network}.` : config.message;
            note.classList.add(valid ? 'is-success' : 'is-error');

            return valid;
        };

        const syncModePanels = () => {
            const activeMode = modeCheckbox && modeCheckbox.checked ? 'test' : 'live';
            Object.entries(panelByMode).forEach(([mode, panel]) => {
                panel.classList.toggle('is-active', mode === activeMode);
            });
        };

        const syncCurrencyNetwork = () => {
            const currency = currencySelect.value;
            const network = app.networkByCurrency[currency] || 'Base';

            if (networkInput) {
                networkInput.value = network;
            }

            if (networkDisplay) {
                networkDisplay.value = network;
            }

            validateWalletField('test');
            validateWalletField('live');
        };

        if (currencySelect) {
            currencySelect.addEventListener('change', syncCurrencyNetwork);
            syncCurrencyNetwork();
        }

        ['test', 'live'].forEach((mode) => {
            const field = qs(`[data-wallet-field="${mode}"]`, settingsForm);

            if (field) {
                field.addEventListener('input', () => validateWalletField(mode));
                validateWalletField(mode);
            }
        });

        if (modeCheckbox) {
            modeCheckbox.addEventListener('change', syncModePanels);
            syncModePanels();
        }

        qsa('[data-test-connection]', settingsForm).forEach((button) => {
            button.addEventListener('click', async () => {
                const mode = button.dataset.testConnection;
                const messageNode = qs(`[data-connection-message="${mode}"]`, settingsForm);
                const badge = qs(`[data-connection-badge="${mode}"]`, settingsForm);
                const hiddenInput = qs(`[data-connection-input="${mode}"]`, settingsForm);
                const payload = {
                    mode,
                    default_currency: currencySelect.value,
                    [`${mode}_api_key`]: qs(`[name="${mode}_api_key"]`, settingsForm)?.value || '',
                    [`${mode}_api_secret`]: qs(`[name="${mode}_api_secret"]`, settingsForm)?.value || '',
                    [`${mode}_wallet`]: qs(`[name="${mode}_wallet"]`, settingsForm)?.value || '',
                };

                button.disabled = true;
                messageNode.textContent = 'Testing connection...';
                messageNode.classList.remove('is-error', 'is-success');

                try {
                    const result = await post('access402_test_connection', payload);
                    const label = app.connectionLabels[result.status] || result.status;

                    if (badge) {
                        badge.textContent = label;
                        badge.className = `access402-badge access402-badge-${result.status}`;
                    }

                    if (hiddenInput) {
                        hiddenInput.value = result.status;
                    }

                    messageNode.textContent = result.message;
                    messageNode.classList.add(result.status === 'connected' ? 'is-success' : 'is-error');
                } catch (error) {
                    messageNode.textContent = error.message;
                    messageNode.classList.add('is-error');
                } finally {
                    button.disabled = false;
                }
            });
        });
    }

    const ruleForm = qs('[data-rule-form]');

    if (ruleForm) {
        const summaryNode = qs('[data-rule-summary]', ruleForm);
        const titleNode = qs('[data-rule-panel-title]');
        const ruleRecords = app.ruleRecords || {};
        let summaryTimer = null;
        let dragSource = null;

        const resetRuleForm = () => {
            ruleForm.reset();
            qs('[name="id"]', ruleForm).value = '0';
            qs('[name="status"]', ruleForm).checked = true;
            if (titleNode) {
                titleNode.textContent = 'Add Rule';
            }
            requestRuleSummary();
        };

        const hydrateRuleForm = (record) => {
            qs('[name="id"]', ruleForm).value = record.id || 0;
            qs('[name="name"]', ruleForm).value = record.name || '';
            qs('[name="path_pattern"]', ruleForm).value = record.path_pattern || '';
            qs('[name="price_override"]', ruleForm).value = record.price_override || '';
            qs('[name="unlock_behavior_override"]', ruleForm).value = record.unlock_behavior_override || '__global';
            qs('[name="status"]', ruleForm).checked = (record.status || 'active') === 'active';
            if (titleNode) {
                titleNode.textContent = 'Edit Rule';
            }
            requestRuleSummary();
        };

        const requestRuleSummary = () => {
            clearTimeout(summaryTimer);
            summaryTimer = window.setTimeout(async () => {
                if (!summaryNode) {
                    return;
                }

                try {
                    const data = await post('access402_preview_rule_summary', new FormData(ruleForm));
                    summaryNode.textContent = data.summary;
                } catch (error) {
                    summaryNode.textContent = error.message;
                }
            }, 180);
        };

        qsa('[data-open-panel="rule"]').forEach((button) => {
            button.addEventListener('click', () => {
                resetRuleForm();
                openPanel('rule');
            });
        });

        qsa('[data-edit-rule]').forEach((button) => {
            button.addEventListener('click', () => {
                const record = ruleRecords[button.dataset.editRule];

                if (!record) {
                    return;
                }

                hydrateRuleForm(record);
                openPanel('rule');
            });
        });

        qsa('input, select', ruleForm).forEach((field) => {
            field.addEventListener('input', requestRuleSummary);
            field.addEventListener('change', requestRuleSummary);
        });

        ruleForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            try {
                const result = await post('access402_save_rule', new FormData(ruleForm));
                renderNotice(result.message);
                window.location.reload();
            } catch (error) {
                renderNotice(error.message, 'error');
            }
        });

        qsa('[data-delete-rule]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!window.confirm('Delete this rule?')) {
                    return;
                }

                try {
                    const result = await post('access402_delete_rule', { id: button.dataset.deleteRule });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        });

        qsa('[data-toggle-rule]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    const result = await post('access402_toggle_rule_status', {
                        id: button.dataset.toggleRule,
                        status: button.dataset.nextStatus,
                    });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        });

        const selectAll = qs('[data-select-all-rules]');

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                qsa('[data-rule-checkbox]').forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        const applyBulk = qs('[data-apply-bulk]');

        if (applyBulk) {
            applyBulk.addEventListener('click', async () => {
                const action = qs('[data-bulk-action]')?.value || '';
                const ids = qsa('[data-rule-checkbox]:checked').map((box) => box.value);

                if (!action || ids.length === 0) {
                    renderNotice('Select rules and a bulk action first.', 'error');
                    return;
                }

                if (action === 'delete' && !window.confirm('Delete the selected rules?')) {
                    return;
                }

                try {
                    const result = await post('access402_bulk_rules', {
                        bulk_action: action,
                        ids,
                    });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        }

        const tbody = qs('[data-rule-table-body]');

        if (tbody) {
            qsa('tr[data-rule-id]', tbody).forEach((row) => {
                row.addEventListener('dragstart', () => {
                    dragSource = row;
                });

                row.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    if (!dragSource || dragSource === row) {
                        return;
                    }

                    const rect = row.getBoundingClientRect();
                    const before = event.clientY < rect.top + rect.height / 2;
                    tbody.insertBefore(dragSource, before ? row : row.nextSibling);
                });
            });

            tbody.addEventListener('drop', async (event) => {
                event.preventDefault();

                if (!dragSource) {
                    return;
                }

                const orderedIds = qsa('tr[data-rule-id]', tbody).map((row) => row.dataset.ruleId);

                try {
                    await post('access402_reorder_rules', { ordered_ids: orderedIds });
                    renderNotice('Rule order updated.');
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                } finally {
                    dragSource = null;
                }
            });
        }

        requestRuleSummary();
    }

    const walletForm = qs('[data-wallet-form]');

    if (walletForm) {
        const records = app.trustedWallets || {};

        const reset = () => {
            walletForm.reset();
            qs('[name="id"]', walletForm).value = '0';
            qs('[name="status"]', walletForm).checked = true;
        };

        qsa('[data-open-panel="wallet"]').forEach((button) => {
            button.addEventListener('click', () => {
                reset();
                openPanel('wallet');
            });
        });

        qsa('[data-edit-wallet]').forEach((button) => {
            button.addEventListener('click', () => {
                const record = records[button.dataset.editWallet];

                if (!record) {
                    return;
                }

                qs('[name="id"]', walletForm).value = record.id || 0;
                qs('[name="label"]', walletForm).value = record.label || '';
                qs('[name="wallet_address"]', walletForm).value = record.wallet_address || '';
                qs('[name="wallet_type"]', walletForm).value = record.wallet_type || 'other';
                qs('[name="status"]', walletForm).checked = (record.status || 'active') === 'active';
                openPanel('wallet');
            });
        });

        walletForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            try {
                const result = await post('access402_save_trusted_wallet', new FormData(walletForm));
                renderNotice(result.message);
                window.location.reload();
            } catch (error) {
                renderNotice(error.message, 'error');
            }
        });

        qsa('[data-delete-wallet]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!window.confirm('Delete this trusted wallet?')) {
                    return;
                }

                try {
                    const result = await post('access402_delete_trusted_wallet', { id: button.dataset.deleteWallet });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        });

        qsa('[data-toggle-wallet]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    const result = await post('access402_toggle_trusted_wallet', {
                        id: button.dataset.toggleWallet,
                        status: button.dataset.nextStatus,
                    });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        });
    }

    const ipForm = qs('[data-ip-form]');

    if (ipForm) {
        const records = app.trustedIps || {};

        const reset = () => {
            ipForm.reset();
            qs('[name="id"]', ipForm).value = '0';
            qs('[name="status"]', ipForm).checked = true;
        };

        qsa('[data-open-panel="ip"]').forEach((button) => {
            button.addEventListener('click', () => {
                reset();
                openPanel('ip');
            });
        });

        qsa('[data-edit-ip]').forEach((button) => {
            button.addEventListener('click', () => {
                const record = records[button.dataset.editIp];

                if (!record) {
                    return;
                }

                qs('[name="id"]', ipForm).value = record.id || 0;
                qs('[name="label"]', ipForm).value = record.label || '';
                qs('[name="ip_address"]', ipForm).value = record.ip_address || '';
                qs('[name="status"]', ipForm).checked = (record.status || 'active') === 'active';
                openPanel('ip');
            });
        });

        ipForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            try {
                const result = await post('access402_save_trusted_ip', new FormData(ipForm));
                renderNotice(result.message);
                window.location.reload();
            } catch (error) {
                renderNotice(error.message, 'error');
            }
        });

        qsa('[data-delete-ip]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!window.confirm('Delete this trusted IP?')) {
                    return;
                }

                try {
                    const result = await post('access402_delete_trusted_ip', { id: button.dataset.deleteIp });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        });

        qsa('[data-toggle-ip]').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    const result = await post('access402_toggle_trusted_ip', {
                        id: button.dataset.toggleIp,
                        status: button.dataset.nextStatus,
                    });
                    renderNotice(result.message);
                    window.location.reload();
                } catch (error) {
                    renderNotice(error.message, 'error');
                }
            });
        });
    }
})();
