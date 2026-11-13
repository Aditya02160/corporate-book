=== Corporate Book - PDF Corporate Book, 3D Corporate Book, PDF embed, PDF viewer ===
Contributors: aditya
Tags: corporate book, 3d book, pdf viewer, pdf embed, pdf
Requires at least: 4.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any PDF into a realistic 3D Corporate Book, 2D Corporate Book, PDF viewer, PDF embed or PDF slider.

== Description ==

Corporate Book creates a PDF Corporate Book, 3D Corporate Book, PDF viewer and PDF embed for WordPress sites. Provide the PDF link, and Corporate Book transforms your PDF into an immersive 3D Corporate Book.

**Engage with Dynamic 3D Corporate Books!** Transform your flat PDFs into captivating and immersive 3D Corporate Books. Say goodbye to static documents and bring your PDFs to life!

### Viewer Modes available in Corporate Book
- **3D Corporate Book** - Your PDFs and images become interactive, lifelike books, powered by WebGL.
- **2D Corporate Book** - A classic flippable book built with CSS and HTML5. Faster on low-end devices.
- **PDF embed and PDF Viewer** - A vertical reader that looks like a browser's embedded PDF viewer. Good for documents with irregular layouts.
- **PDF Slider** - Shows PDF pages as slides.

### File Formats Supported by Corporate Book
- **PDF / PDF Corporate Book** - Built on PDF.js. All it takes is a link to the PDF file.
- **Image / Image Corporate Book** - Turn a collection of images into a flippable album or portfolio.

### How easy is Corporate Book? Just 5 Steps!

1. Install the Corporate Book plugin.
2. Create a new Corporate Book post.
3. Provide a link to your PDF file and save the post.
4. Copy the generated shortcode, for example `[corporate_book id="123"][/corporate_book]`, and paste it into a page or post.
5. Publish the page or post, and your interactive 3D Corporate Book is ready to flip!

### Corporate Book Feature List
* **Realistic 3D Corporate Book** powered by WebGL.
* **Reliable 2D Corporate Book** for low-end devices.
* WordPress post structure for creating books, and a shortcode to display them.
* Supports both **PDF and image formats**.
* Embedded inline Corporate Book as the default display.
* Popup (lightbox) display with `type="thumb"`, ideal for many books on one page.
* Table of contents for PDF documents, plus a custom table of contents creator.
* **Slider Mode** shows PDF pages as slides.
* Page thumbnails preview.
* **Deep linking** to specific pages via the share button.
* Categories for organizing Corporate Books, and a shortcode to show a whole category.
* **RTL mode** for right-to-left languages.
* Supports more than 1000 pages and files over 500MB.
* Partial loading (on-demand pages): large files start with a small amount of data.

### TOP FEATURES
All of these are set for every book at once in **Corporate Book > Global Settings**.

* **Global Settings** - Control display mode, size, background, flip duration, page mode, sound and autoplay for all Corporate Books at once.
* **PDF Links** - Links inside your PDFs are clickable.
* **PDF Links Target** - Open PDF links in the same tab or a new tab.
* **Controls Customization** - Choose which controls to show or hide.
* **Search Control** - Add a search button to the toolbar.
* **Paper Flexibility** - Set how stiff the 3D pages are.
* **Text Translation** - Translate the text of the controls and buttons, or use your translation plugin.
* **Google Analytics** - Track when readers open Corporate Books.
* **Stage Padding** - Set the padding between the 3D Corporate Book and its container.
* **Zoom on Mouse Scroll** - Turn mouse-wheel zoom on or off.
* **Zoom Ratio** - Choose how much each zoom step zooms.
* **Share Prefix** - Change the share-link prefix. The default is `corporate-book-`.
* **PDF Attachment Page** - PDF media attachment pages show the PDF as a Corporate Book.
* **PDF Partial Loading Size** - Tune how much of the PDF each request loads.
* **Spiral 3D Corporate Book** - Show a spiral binding on 3D books.
* **3D Hybrid Corporate Book** - 3D on computers and the lighter 2D book on phones, chosen automatically.
* **Calendar Mode** - 3D books bound at the top that flip upward, like a wall calendar.
* **3D Tilt and Rotation** - Lean the 3D book back or to the side, and rotate PDF pages.
* **Book Shelf** - `[corporate_book books="all" shelf="true"]` shows your books on a shelf.
* **Trigger Lightbox and Link Lightbox** - Open a book in a popup from a button, a text link or your own HTML: `type="button"`, `type="link"` or `type="custom"`.
* **Corporate Book Post URLs** - Give every book its own page at `/corporate-book/book-name/`.
* **Auto Generate Thumb** - The book editor makes the thumbnail from the first page of the PDF.

### Subscription
Lock Corporate Books behind a subscription. Set it up in **Corporate Book > Global Settings > Subscription**:

1. Install a social login plugin (for example Nextend Social Login) so readers can sign in with Google or LinkedIn, and paste its shortcode in **Social Login Shortcode**.
2. Set **Subscription Lock** to True and paste your **Subscribe Page Link**.
3. In **Subscriber Roles**, list the roles your membership plugin gives paying members, for example `cb_member`. Do not use `subscriber`: WordPress gives it to every new account.
4. In each book, click **Create preview** to make the free preview (the first 10% of pages by default), then click **Update**.

How it works:

* Everyone, signed in or not, sees the thumbnail, cover, title and the free preview. A Preview button sits under each thumbnail.
* Subscribers read every page. Everyone else reads the free preview, then sees a "Your free preview has ended" panel with Subscribe and Sign in buttons.
* Saving a locked book moves its full PDF into a protected folder. Only readers with access can load it.
* Each book can override the global setting with **Required** or **Free**. Authors and editors always see the full book.
* Pages with locked books are not cached, so one reader's view is never shown to another.
* Image books: the lock limits the pages shown, but the image files stay public in the Media Library. Use a PDF book for paid content.

On nginx, add this rule so the protected folder cannot be opened directly (Apache uses the included .htaccess):

`location ^~ /wp-content/uploads/corporate-book-protected/ { deny all; }`

Developers can change who counts as subscribed with the `corporate_book_user_is_subscribed` filter ( `$subscribed`, `$user`, `$book_id` ).

== Installation ==

1. Upload the `3d-corporate-book` folder to `/wp-content/plugins/`, or upload the zip in **Plugins > Add New**.
2. Activate **Corporate Book**.
3. Go to **Corporate Book > Add New**.

== Changelog ==

= 1.0.0 =
* First release of Corporate Book.
* Subscription lock: sign-in gate, free preview, Subscribe link and protected PDFs.
