# PropertyHive Buffer Auto Post

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Homer Estates administrators configure automatic social posting and verify individual property posts from WordPress admin. Property imports and ordinary listing updates run without requiring an administrator to wait for Buffer.

## Product Purpose

The plugin turns reliable Property Hive listing transitions into traceable Facebook and Instagram posts in Buffer. Success means eligible events are detected once, delivered in the background to every selected channel, and can be audited without exposing credentials or interrupting property management.

## Positioning

The automation is driven by Property Hive's actual listing state and photography. Administrators configure the policy once instead of copying property facts and images into a separate social publishing workflow.

## Operating Context

The plugin runs beneath the Property Hive WordPress menu. Administrators connect Buffer, choose channels and delivery timing, maintain three caption templates and a sold overlay, send draft tests, and review the latest delivery log.

## Capabilities and Constraints

- Automatic events cover sales departments only: first live listing, price reduction, and the first Sold STC or Sold transition.
- Existing properties are baselined without posting when the plugin is activated.
- Buffer receives one independently tracked post per Facebook or Instagram channel.
- Automatic posts use the next Buffer queue slot or the next configured daily time; manual tests are Buffer drafts.
- Property Hive images keep their original order and are not cropped by the plugin. Sold posts composite a transparent PNG over a derived copy of the first image.
- PHP 7.4+, WordPress 6.5+, and Property Hive 2.0.16+ are required. There are no third-party runtime dependencies.

## Brand Commitments

The admin experience follows native WordPress patterns and terminology. It is operational, restrained, accessible, and uses the product name “PropertyHive Buffer Auto Post.”

## Evidence on Hand

- `docs/buffer-integration-spec.md` is the authoritative functional specification.
- Property Hive 2.0.16 is installed locally and provides the `property` post type, sales departments, availability taxonomy, `_price_actual`, `_on_market`, `_photos`, and `_photo_urls` fields.
- No production Buffer key, selected organisation, channel IDs, or sold-overlay artwork is included in the repository.

## Product Principles

- Never block a Property Hive save or import on external publishing.
- Prefer preventing duplicates over automatically retrying an uncertain delivery.
- Make configuration and failure recovery understandable to a WordPress administrator.
- Preserve source property data and photography.
- Keep credentials and raw provider responses out of the interface and logs.

## Accessibility & Inclusion

Every administrative action is keyboard operable, uses labelled native controls, provides live status text, and does not rely on colour alone.
