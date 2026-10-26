# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.4.0 — 2026-10-26

### Changed

- Factory registrations declare the services they resolve with `uses()`, so the container checks
  their lifetimes at startup (FE-40).
- Requires `mj-zana/http` ^2.4.

## 2.3.0 — 2026-10-26

### Added

- `Router::redirect()` and `RedirectHandler`: redirect routes to a route name, a path or an http(s) URL,
  with a status and extra headers, exportable to the route cache (FE-14).

### Changed

- Tests and tooling are no longer in Composer archives (`export-ignore`).

## 2.2.0 — 2026-10-26

### Added

- Route metadata: `Route::meta()`, `getMeta()`, `#[Route(meta: ...)]` and `LocalizedRoute::meta()`,
  kept in the route cache (FE-21).

### Changed

- `UrlGenerator` adds the application's base path to generated URLs; its third constructor
  argument is the base path (FE-11).
- Requires `mj-zana/http` ^2.2.

## 2.1.0 — 2026-10-26

### Added

- `Router::localized()`: one route per locale, named `<name>.<locale>`, with the `_locale` default
  (FE-03).
- `UrlGenerator::alternates()`, and URL defaults (`UrlDefaults`) filled from the matched route.
- Route defaults (`Route::defaults()`), exported with the route cache.
- Result converters: services tagged `routing.result_converters`.

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Routes, groups, attribute routes, URL generation and controller dispatch, on top of `mj-zana/http`. Requires PHP 8.5 or later.

Documentation: [the routing guide](https://github.com/mjnable/zana-docs/blob/main/guides/routing.md).
