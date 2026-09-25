# Examples

`shop/` is the whole hosted-checkout integration in four files, the way a
Laravel store would write it:

| File | Step |
|---|---|
| `PayController.php` | 1. create + 303 redirect, 2. return handler (query = hint, `get()` = truth) |
| `OrderTransition.php` | 3. the ONE idempotent transition with the amount/currency/reference guard |
| `register.php` | 4. `CheckoutCompleted` listener, 5. the five-minute reconciler |
| `Order.php` | a stand-in for your Eloquent model |

The classes are autoloaded in dev (`DPay\Laravel\Examples\Shop\`) and analysed by
PHPStan with the tests, so they cannot drift from the package's API.
