# PropertyHive Buffer Auto Post

Automatically creates Buffer posts when a Property Hive sales listing first goes live, its asking price is reduced, or it first reaches Sold STC/Sold.

## Requirements

- PHP 7.4 or newer
- WordPress 6.5 or newer
- Property Hive 2.0.16 or newer
- A Buffer API key and at least one connected Facebook or Instagram channel
- HTTPS property and uploads URLs that Buffer can reach publicly

## Setup

1. Activate the plugin and allow its background baseline to complete.
2. Open **Property Hive → Buffer Auto Post**.
3. Save a Buffer API key, then use **Test connection and refresh**.
4. Select the organisation and destination channels.
5. Configure caption templates and upload a transparent PNG sold overlay.
6. Send a draft from the Test tab and confirm it in Buffer.
7. Enable the required automatic events.

The API key may instead be defined in `wp-config.php`:

```php
define( 'PHBAP_BUFFER_API_KEY', 'your-key' );
```

`PHBAP_BUFFER_API_URL` can override the GraphQL endpoint for a controlled test environment.

## Delivery safety

Property writes only create local events; Buffer requests run through WP-Cron. Each channel is tracked independently. Definitive throttling can retry up to three total attempts, while ambiguous timeouts are marked uncertain and are not resent automatically.

Buffer fetches media from public URLs when publishing. Generated sold images remain in `uploads/phbap/` until every related Buffer post reports a terminal sent/error state.

## Development

Install development-only tools with `composer install`, then run:

```sh
composer lint
composer test
```

Set `WP_TESTS_DIR` to include the optional WordPress integration suite. Without it, the pure caption and scheduling unit tests still run.
