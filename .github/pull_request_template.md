Closes #

## What and why
<!-- What this changes, and the reason. -->

## How it was tested
<!-- New or changed tests, and anything checked by hand. -->

## Checklist
- [ ] Backend: `php artisan test` and `vendor/bin/pint --test` pass (tests run on MySQL)
- [ ] Frontend: `npm run typecheck`, `npm run lint` and `npm test -- --run` pass
- [ ] Money stays in whole rupees and times in UTC
- [ ] No private data exposed (reserve prices, automatic-bid maximums, other bidders' identities)
- [ ] README updated if behaviour, setup or the API changed
