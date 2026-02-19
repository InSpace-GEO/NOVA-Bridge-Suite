# WPBakery Bridge (REST API for n8n & friends)

Minimal REST bridge for **WPBakery Page Builder**.  
Exposes endpoints to:

- List pages/posts
- Fetch a WPBakery “outline” (compact, path-based view of text nodes)
- Get the raw shortcode document
- Apply content transformations:
  - Remove sections by `path`
  - Update text by `path`
  - Append HTML or structured “sections”
- Clone existing pages (including meta) and transform them

Designed to be easy to use from tools like **n8n**, custom scripts, or other automation platforms.

---

## Installation

1. Create the plugin folder:

   ```text
   wp-content/plugins/wpbakery-bridge/
