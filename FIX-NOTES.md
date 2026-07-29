# Native XMLReader initialization fix

This patch fixes a native `XMLReader` failure that occurred immediately after
`open()`/`XML()` and before the first `read()` call. Some PHP/libxml builds throw
`Error: Failed to read property due to libxml error` when properties such as
`isEmptyElement` are accessed while the reader is unpositioned.

## Changed

- Do not synchronize native node properties until `read()` succeeds.
- Keep public state at `NONE` after `open()`/`XML()`.
- Reset public state after end-of-stream.
- Read `isEmptyElement` and `hasAttributes` only for element nodes.
- Convert unexpected native property failures into a contextual exception.
- Add a regression test that reproduces strict unpositioned property behavior.

## Release

Recommended package release: `mnb/mnb-phpexcel-core` `v2.0.1`.
`mnb/mnb-phpexcel-xlsx` can remain at `v2.0.0` because it requires core `^2.0`.
