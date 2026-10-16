# The FreeITSM test suite

Developer tooling. **Not part of the application**, and of no use to anyone who
merely runs FreeITSM.

Full documentation, one page per group of tests, is on the wiki:
**[Developer Tests](https://github.com/edmozley/freeitsm/wiki/Developer-Tests)**.

## Running them

```
php tests/<name>.php
```

From the repository root, on the **command line**. Some need a database; a few
need nothing at all. Each page on the wiki says which, and what a failure means.

## 🔴 They never run over the web

Every script here starts with:

```php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
```

That is deliberate and must not be removed. FreeITSM is normally deployed by
putting this repository in the document root, so without it a request to
`/tests/<anything>.php` runs the file.

These are not pure unit tests. They drive the real code against the real
database — creating forms, assets, contracts, documents and working analyst
accounts — and each one deletes what it made only in its closing lines. Served
over HTTP that becomes an unauthenticated write endpoint which a request can
abandon, or a web server's execution limit can cut off, part way through. Their
failure messages also print real records back to whoever asked.

Three layers keep them closed, and each covers something the others cannot:

| Layer | Covers | Does not cover |
|---|---|---|
| The `PHP_SAPI` guard in each file | Every server, including a plain git clone | `.html` and `.sh` files, which cannot refuse for themselves |
| `tests/.htaccess`, `tests/web.config` | The whole directory, all file types | nginx, and Apache without `AllowOverride` |
| `.dockerignore` + `deploy/nginx/freeitsm.conf` | The official image; the nginx we ship | An image or config somebody wrote themselves |

`php tests/web-exposure-guard.php` asserts all three are still in place, and
takes its file list from the directory rather than from a list, so a test added
tomorrow is checked tomorrow.

**The exceptions** are the stand-in endpoints, `tests/azure-openai/mock.php`
and `tests/custom-openai/mock.php`. They answer HTTP by design, so each requires
`cli-server` instead — the built-in server the matching `run.php` starts on a
free port and stops again. Each refuses under Apache, nginx and php-fpm.

## Writing a new one

Start with the guard, then the docblock. The convention in this suite is that a
test explains **why it exists** — the failure it was written after — not just
what it asserts. And give it a **control**: an assertion that fails if the
checker is broken. Several tests here have caught themselves that way, including
one whose check was satisfied by its own comment.
