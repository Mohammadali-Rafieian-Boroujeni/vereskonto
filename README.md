# Vereskonto

WordPress / WooCommerce plugin for Vereskala: SMS login, wallet, installments, customer club, tickets, returns, smart shop (chatbot) and a mobile navigation bar.

| Path | What |
|---|---|
| `vereskonto/` | The plugin (version 2.20.0). Install by zipping this folder and uploading it in WordPress. |
| `vereskonto/CHANGELOG.md` | Change log (Persian). |
| `mu-plugins/vk-search-tap-fix.php` | Must-use plugin: stops WoodMart's closed full-screen search from swallowing taps on mobile. Upload to `wp-content/mu-plugins/`. |
| `docs/CRM-API.md` | REST API reference for the staff mobile app (Persian): sign-in, endpoints, sync, errors. |
| `docs/CRM-PLAN.md` | Review of the plugin and the CRM development plan (Persian). |
| `tools/po2mo.php` | Compiles a `.po` translation into `.mo` without gettext: `php tools/po2mo.php vereskonto/languages/vereskala-login-fa_IR.po`. |
| `docs/IA.md` | Information-architecture proposal for the admin and customer area, and what was built (Persian). |

## Tests

Plain PHP, no WordPress needed:

```bash
for t in vereskonto/tests/*-test.php; do php "$t" > /dev/null && echo "PASS $t" || echo "FAIL $t"; done
```

Integration test for the CRM (real WordPress + WooCommerce + MySQL). **It deletes data — run it only on a throwaway copy, never on the live site:**

```bash
VKL_WP_LOAD=/path/to/throwaway/wp-load.php VKL_I_KNOW_THIS_DELETES_DATA=1 php vereskonto/tests/integration/crm-integration.php
```
