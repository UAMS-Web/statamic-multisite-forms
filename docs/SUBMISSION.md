# Packagist and Marketplace submission checklist

Nothing on this page has been done. No Packagist registration, seller account, product or release exists, and the decision to submit has not been made. This is the list of real-world steps, written down so they are known before they are needed. The add-on is free with no editions and MIT licensed (decision log, #17).

Each step is marked **Person** when it needs someone with an account, or **Repo** when it is a change here.

## Before anything is submitted

1. **Repo.** The README describes only what exists, and the features it lists are real. #163 rewrote *Status* and *What it does* to describe what ships; check it again before submitting.
2. **Repo.** `composer.json` is complete for a public package: `name`, `description`, `keywords`, `type` of `statamic-addon`, `license`, `authors`, `support`, `autoload`, `extra.statamic` (`name`, `description`) and `extra.laravel.providers`. A test (`tests/Unit/SubmissionReadinessTest.php`) pins these.
3. **Repo.** The built Control Panel assets are committed (`resources/dist`) and the `Built assets` job is green, because a Composer install does not run npm.
4. **Person.** Record the decision to submit as a comment on #26, made by the operator. This is the gate: no later step starts before it.

## Packagist

5. **Person.** Sign in to packagist.org and submit `https://github.com/UAMS-Web/statamic-multisite-forms`. The package becomes `uams-web/statamic-multisite-forms`, from `composer.json`.
6. **Person.** Confirm Packagist updates when a tag is pushed (its GitHub integration or an update hook on the repository). Until it does, new tags do not appear.

## First release

7. **Person.** Cut the first tag per the `cutting-releases` rule and the `writing-release-notes` skill, on the operator's authorization. The Marketplace lists versions from tags, so a package with no tag has nothing to list.

## Statamic Marketplace

8. **Person.** Create a statamic.com seller account (`https://statamic.com/creator/begin`). Decide whether it is personal or institutional before creating it.
9. **Person.** Bank details: the guide ties these to charging for an add-on, so a free listing is expected not to need them. Unverified.
10. **Person.** In the seller dashboard, create a product linked to the Packagist package, with a price of zero and a description. Products start as drafts and can be previewed before publishing.
11. **Person.** Publish the product only when the operator says so. Once published, the add-on appears on the Marketplace and in the Control Panel's Addons area.

## Unverified

- Whether a free listing needs bank details or a seller account type beyond the default.
- Whether the price, or the edition list, can change after the first public tag. Adding `["free", "pro"]` later with `free` first leaves installs on `free` (measured in `docs/DEVELOPMENT.md`); the Marketplace side was not measured because no product exists.
- Whether the Marketplace requires anything about the license beyond a declared one.

## Source

Statamic's guide, Building an Addon (Marketplace Submission and Editions). The Marketplace and editions pages themselves could not be retrieved when this was written, so the steps above follow the guide's summary and are to be re-read against the live seller dashboard before step 4.
