# tools/

One-off maintenance scripts. Run by hand, never by the deploy.

## generate_tests.php (removed)

Scaffolded one `*ApiTest` per controller, guessing each endpoint from the
controller's class name: `OrderItemController` became `/api/v1/order-items`.

It was removed rather than fixed, for two reasons.

**The guess was wrong wherever a resource is nested.** `orders.items`,
`products.media`, `quotations.items` and six others are registered
`->shallow()`, so the collection URL it invented never existed. Those tests
asserted that a 404 was a failure, when the 404 was the correct answer to a
made-up question - thirty red tests that everyone learned to ignore, which is
how a real failure (`EnvUsageTest`) sat unnoticed among them.

**It overwrote by hand-written work.** `file_put_contents` with no check, so
re-running it would have silently replaced the twenty real tests in
`PurchaseOrderApiTest` and the six in `InventoryItemApiTest` with two vacuous
ones. A script that destroys tests when run is worse than no script.

What it was reaching for - "every endpoint answers something" - is now
`tests/Feature/ApiRouteSmokeTest.php`, which reads the actual route table
instead of guessing at names. New resources are covered with no edit, and a
renamed one cannot leave an assertion behind pointing at nothing.
