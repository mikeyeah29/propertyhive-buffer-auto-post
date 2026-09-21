## Project Overview

This WordPress plugin is for the Homer Estates website, which uses
Property Hive to manage property listings.

The plugin automatically sends property-related social posts to Buffer.
Buffer will then schedule and publish those posts to selected Facebook
and Instagram channels.

Before implementing any integration, inspect the installed versions of
WordPress, Property Hive and the existing codebase. Do not guess Property
Hive hooks, metadata keys, statuses or API behaviour.

## Primary Requirements

The plugin supports three event types:

1. A new property is published
2. The asking price of a published property is reduced
3. A property is marked as sold

Enabled events should create a corresponding post in Buffer.

## Important Behaviour

### Duplicate Prevention

Each event must be idempotent.

The plugin must not send the same event more than once because a property
is saved repeatedly, updated by an import, or processed by WP-Cron.

Store sufficient metadata to identify each sent event.

For price reductions, record the previous price and the new price. A new
price-reduction post may be sent only when the price is reduced again.

For sold properties, send only when the property transitions from a
non-sold state into the configured sold state.

### Background Processing

Do not make the complete Buffer request during the Property Hive save or
import operation.

Queue the event for background processing using an appropriate WordPress
mechanism. Ensure failed requests do not block property imports or saves.

Retries must be limited and must not create duplicate Buffer posts.

### Property Hive Integration

Determine the appropriate Property Hive hooks and property fields by
inspecting the installed Property Hive plugin and its available
documentation.

Do not assume that a generic `save_post` event alone is sufficient.

The plugin must handle properties created or updated by:

- WordPress admin
- Property Hive imports or feeds
- Programmatic updates

Document the hooks and fields chosen before implementing the event
listeners.

## Buffer Integration

Use the current official Buffer API documentation:

https://developers.buffer.com/

Use the WordPress HTTP API for all requests.

Authentication credentials must never be written to logs or displayed
unmasked after saving.

The Settings page must allow an administrator to:

- Enter and update the Buffer API key
- Test the Buffer connection
- Select the Buffer organisation/account if required
- Select one or more Facebook and Instagram channels
- Choose whether posts are added to the Buffer queue or scheduled using
  another supported API option

Do not hard-code API endpoints, account IDs or channel IDs.

Return useful, human-readable error messages when authentication,
validation or API requests fail.

## Social Post Format

Each Buffer post should contain:

- A caption generated from the relevant template
- A carousel of Property Hive property images
- A link to the property where supported and configured

Use the original Property Hive images without manually cropping them.

Respect the current media count, file type, file size and aspect-ratio
requirements of Buffer, Facebook and Instagram.

If a property has more images than the destination allows, use only the
maximum supported number.

If a property has no usable images, do not silently create a broken post.
Log the failure and show it in the admin log.

### Sold Overlay

For sold posts only, apply an administrator-uploaded transparent PNG
overlay to the first property image.

Requirements:

- Preserve the underlying image’s aspect ratio
- Preserve PNG transparency
- Do not modify or overwrite the original Media Library image
- Generate a temporary or derived image for posting
- Clean up generated files when they are no longer required
- If no overlay is configured, report the validation error rather than
  silently producing an unexpected result

## Caption Templates

Provide one editable caption template for each event:

- New listing
- Price reduction
- Property sold

Support documented placeholders such as:

- `{property_id}`
- `{address}`
- `{price}`
- `{previous_price}`
- `{bedrooms}`
- `{property_type}`
- `{property_url}`

Only expose placeholders that can reliably be populated from Property
Hive.

Before sending, replace placeholders with property data and ensure
unresolved placeholders do not appear in the final post.

## WordPress Admin Pages

Add a submenu beneath the existing Property Hive admin menu.

The plugin page should use the following tabs.

### 1. Settings

#### Post to Buffer when

- [ ] New property is listed
- [ ] Property price drops
- [ ] Property is sold

#### Buffer settings

- Buffer API key
- Connection test
- Buffer account/organisation selection, if required
- Facebook and Instagram channel selection
- Queue or scheduling behaviour

### 2. Templates

This page should include:

- New-listing caption template
- Price-reduction caption template
- Sold-property caption template
- A list of supported placeholders
- A WordPress Media Library control for selecting the sold PNG overlay
- A preview or validation facility where practical

### 3. Test

Allow an administrator to perform a manual test.

The administrator should be able to:

1. Search for and select a Property Hive property
2. Select one of the three templates
3. Preview the generated caption and selected images
4. Send the test to Buffer

A manual test must be clearly identified as a test in the log.

It must not change the automatic-event history or prevent a future real
property event from being sent.

Require a nonce and an appropriate administrator capability.

### 4. Log

Display the most recent 100 log entries.

Each entry should include:

- Timestamp using the WordPress-configured timezone
- Property ID
- Event type
- Whether the action was automatic or a manual test
- Destination Buffer channels
- Success, failure or retry status
- Buffer post/update ID when available
- A sanitised error message when unsuccessful

Example:

`Property 1234 price reduced from £500,000 to £475,000 — sent to Buffer
at 21 September 2026 14:30.`

Provide an administrator action to clear the log.

Do not store API keys, access tokens, complete API responses or other
sensitive information in logs.

Use an appropriate WordPress storage mechanism. Do not place a publicly
accessible plain-text log file inside the plugin directory.

## Architecture

Use object-oriented PHP with a unique project namespace.

Prefer small classes with clearly separated responsibilities rather than
forcing every component into a strict MVC pattern.

Suggested responsibilities include:

- Plugin bootstrap and dependency checks
- Property Hive event detection
- Event deduplication
- Background queue processing
- Buffer API client
- Caption generation
- Property image collection
- Sold-overlay image generation
- Settings management
- Admin screens
- Logging

Use dependency injection where it meaningfully improves testability.
Avoid unnecessary singleton classes and global state.

Keep the main plugin file limited to metadata, constants, autoloading and
plugin bootstrap.

## WordPress Standards and Security

Follow the WordPress Plugin Handbook:

https://developer.wordpress.org/plugins/plugin-basics/best-practices/

In particular:

- Use a unique namespace and unique option/meta names
- Use WordPress APIs instead of direct filesystem or HTTP operations
  where an appropriate API exists
- Check capabilities for every administrative action
- Use nonces for state-changing admin requests
- Validate and sanitise all input
- Escape all output at the point it is rendered
- Use prepared SQL queries if custom SQL is required
- Prevent direct access to executable PHP files where appropriate
- Make all user-facing strings translatable
- Load scripts and styles only on this plugin’s admin screens
- Do not expose secrets in HTML, JavaScript, logs or error messages

Only users with an appropriate capability, normally
`manage_options`, may configure the plugin, send tests or clear logs.

## Compatibility and Dependencies

The plugin requires Property Hive to be installed and active.

If Property Hive is unavailable:

- Do not cause a fatal error
- Do not register property event listeners
- Show an administrator notice explaining the dependency

Determine and document the supported minimum versions of:

- PHP
- WordPress
- Property Hive

Do not introduce third-party runtime dependencies without first
explaining why they are required.

## Development Workflow

Before writing implementation code:

1. Inspect the repository structure and existing coding conventions.
2. Inspect the installed Property Hive integration points.
3. Read the current Buffer API documentation.
4. Identify any unresolved product decisions or technical blockers.
5. Propose the plugin architecture and implementation stages.
6. Wait for confirmation if a decision would materially affect behaviour.

When making changes:

- Keep changes focused on the requested feature
- Do not modify Property Hive, WordPress core or unrelated plugins
- Do not overwrite existing user changes
- Run the available linting and tests
- Add automated tests for event detection, template replacement,
  deduplication and API error handling where the project supports them
- Report which checks were run and any checks that could not be run

## Acceptance Criteria

The first version is complete when:

- Each enabled Property Hive event creates exactly one corresponding
  Buffer post
- Repeated property saves do not create duplicate posts
- Disabled events create no posts
- Captions correctly replace all supported placeholders
- Property images are attached as a carousel within platform limits
- Sold posts apply the configured overlay without modifying originals
- Manual tests can be previewed and sent
- Failed API requests are handled without breaking property updates
- The most recent 100 log records can be viewed in WordPress admin
- Credentials and administrative actions are secured