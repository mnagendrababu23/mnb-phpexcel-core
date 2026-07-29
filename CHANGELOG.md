# Changelog

## 2.0.1
- Fixed native XMLReader initialization on PHP/libxml builds that reject property access before the first `read()` call.
- Reset reader state after EOF and guard element-only native properties.

## 2.0.0
- Hardened the package-local ZIP reader so a missing zlib inflater fails safely instead of causing an undefined-function fatal error.

- Coordinated MNB PHPExcel v2 release.
- Internal MNB dependencies aligned to `^2.0`.
- Package boundaries validated for independent installation.
