# Images in saved publishing layouts

The first production catch-up articles had valid generated image URLs, but their saved layouts contained no image destinations. Text publishing succeeded while featured images remained unset and decorative template images stayed unchanged.

Image placement is now an explicit part of the layout mapping:

- **WordPress featured image** is available for native post types that support thumbnails, independently of the selected builder. Select its Image URL purpose to use a supplied source image.
- **Elementor image controls** are discovered from registered MEDIA controls. This includes image widgets, third-party widgets, container/section/column backgrounds, registered responsive variants and fixed repeater entries present in the captured example. Dynamic/global controls remain protected.
- Unselected images remain part of the saved design. Mapping an image does not automatically replace every image on the page.

Posting-service receives ordinary V3 URL destinations, with image source hints. It does not receive WordPress attachment IDs or builder media objects. WordPress resolves the selected URL, imports a local attachment, and writes the native featured-image ID or the paired Elementor attachment ID and URL. The same source URL is reused within a retained publishing operation.

The download checks and durable import journal are shared with NOVA-owned CPT publishing. An unavailable optional image is recorded as skipped; the saved template image is preserved. An uncertain attachment result must be reconciled against the same operation before proceeding. Existing mappings and operations without the new media binding remain compatible.

## Updating an existing mapping

1. Open the destination's creation default and capture the example again if its image controls have changed.
2. Select **WordPress featured image** and/or the desired image fields. Assign Image URL and add placement instructions where several source images exist.
3. Preview and save the mapping; confirm its posting-service synchronization.
4. Future deliveries using that revision can supply images. Saving a mapping does not rewrite already published articles or the payload of an existing delivery.

A featured image is stored even if the active theme does not display it in the article body. Select an inline image field or use the theme's featured-image element when an image should appear there.

Inline serialization is builder-specific: the new attachment-pair handler covers registered Elementor media controls. Other builders keep their existing image-field behavior; their native adapters can be extended separately. The WordPress featured-image handler is shared across supported native publishing providers.
