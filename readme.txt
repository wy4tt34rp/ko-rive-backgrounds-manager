=== KO – Rive Manager ===
Contributors: ko
Tags: rive, animated background, hero, page loader, shortcode, animation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage Rive backgrounds, heroes, and page loaders in one Library with one shared runtime.

== Description ==

KO – Rive Manager provides one reusable Library for Rive backgrounds, heroes, and page loaders. Every item has a unique class / shortcode ID, a role, and a Preload or Lazy Load policy.

The assigned class is also the exact shortcode identifier. For example:

`yellow-navigation`

and:

`[ko_rive id="yellow-navigation"]`

The plugin does not add another prefix. Each Library item receives its own ID and its own complete shortcode.

Backgrounds can be attached to an existing Divi or WordPress module with the assigned class, or placed with the shortcode. Heroes retain a dedicated in-flow hero renderer. Page Loader items include their own targeting, priority, frequency, adaptive timing, and accessibility settings. When multiple enabled loaders match a page, the lowest priority number runs.

Adaptive Page Loaders wait through a short configurable grace period. Fast pages suppress the overlay completely. Slower pages show it while images, videos, iframes, and managed Rive items intersecting the initial viewport settle, then exit after two stable paint passes. Offscreen resources and the loader's decorative Rive animation do not delay the exit, and Maximum wait provides a bounded release.

Eligible same-tab internal link clicks also arm a short navigation handoff. If the destination responds immediately, the loader remains suppressed. If the document response is slower, the current page starts the loader promptly and passes its timing state to the destination so the initial visible content remains covered.

When the outgoing loader has painted a useful frame, the Manager captures a bounded WebP snapshot and carries it with that same-tab handoff. The destination paints the captured frame in its early head shell, keeps it over the restarted Rive canvas until that canvas produces useful branded paint, then crossfades between them. This removes the solid fallback frame at document replacement without trying to persist a live canvas across documents.

The bundled Rive runtime is loaded once. Files marked Preload receive head preload hints. Files marked Lazy Load are requested when their visible placement approaches the viewport. Hidden navigation modules are not initialized until they become visible. Multiple placements of the same Rive asset share one browser fetch.

A fallback color is painted before the animation is ready. An optional poster or first-frame image can be displayed immediately and faded out after Rive loads.

The public plugin contains no customer Rive files or URLs.

Rive files may be added with a public share link, a direct `.riv` URL, or a validated local `.riv` upload. Uploaded files are stored in the Manager's WordPress uploads directory and are not added to the plugin package.

== Safe migration ==

On first use, version 3 copies existing records from:

* KO – Rive Backgrounds Manager 1.x
* KO – Rive Hero
* KO – Rive Page Loader

The original options and locally stored files are not modified or deleted. Existing Background Manager classes and `[ko_rive_background]` shortcodes remain available. The legacy `[ko_rive_hero]` shortcode is supported when the standalone Hero plugin is inactive. The standalone Page Loader takes precedence while it remains active, preventing two fullscreen loaders during the transition.

The Library includes a Re-import / Repair Legacy Items action. It adds missing copies and fills missing matching metadata without overwriting existing Library values.

== Installation ==

1. Upload the plugin ZIP in Plugins > Add New > Upload Plugin.
2. Activate KO – Rive Manager.
3. Open the top-level Rive Manager menu and confirm the copied Library items.
4. Assign each item its desired Background, Hero, or Page Loader role and Preload or Lazy Load policy.
5. Use the displayed class or unique `[ko_rive id="assigned-class"]` shortcode.
6. After verifying the unified Hero and Loader on staging, deactivate the standalone KO – Rive Hero and KO – Rive Page Loader plugins.

== Frequently Asked Questions ==

= Does the plugin include sample or default Rive files? =

No. A new public installation begins with an empty Library.

= Does every Rive file have a unique shortcode? =

Yes. If the assigned class is `neutral-background`, the complete shortcode is `[ko_rive id="neutral-background"]`. A different file receives a different assigned class and therefore a different shortcode.

= Can I still add the class to an existing module? =

Yes. Add the exact assigned class to the module. The manager detects modules added later by Divi and waits for hidden modules, such as navigation overlays, to become visible before initializing them.

= What is the difference between Preload and Lazy Load? =

Preload adds early browser hints for the shared runtime and that Rive file. Lazy Load waits until a matching visible placement approaches the viewport. A Page Loader marked Lazy Load still starts immediately when its targeting rule matches, but it does not receive a head preload hint.

= How does the plugin prevent a white flash? =

Backgrounds and Heroes paint their configured fallback color immediately. An adaptive Page Loader waits through its Grace setting first, so fast pages do not flash an unnecessary overlay. If the page is still waiting on visible media, the fallback color and loader cover it until that initial viewport settles.

= What do the adaptive Page Loader timings mean? =

Grace is how long a matching page gets to paint its initial viewport before the overlay appears. Minimum visible prevents a loader that has appeared from flashing away instantly. Maximum wait is the hard viewport-readiness limit. Exit pause and Fade control the final transition. The legacy Completion event value is retained for compatibility but does not hold the adaptive loader open.

For an eligible internal navigation click, the Manager uses the shorter of Grace or 120 ms before showing the outgoing loader. Modified clicks, new-tab links, downloads, external links, and same-page anchors are left alone. Browser back/forward restores that already have a ready page also remain loader-free.

The captured handoff frame is validated, capped at 350,000 characters, stored only in same-tab session storage, bound to the exact destination and loader ID, and removed when the destination reads it. Capture or storage failure falls back to the normal bounded loader without delaying navigation. A Rive state machine cannot be serialized across full-document navigation, so the destination starts a new instance behind the carried frame and crossfades when ready.

= What happens to the old plugin settings? =

They remain unchanged. Version 3 writes the unified Library to a separate option and only copies references to existing managed files. Removing a migrated Library item does not delete a file owned by an older plugin.

= Can a protected staging Media Library URL be used? =

Yes. Choose Use the URL directly. This avoids a server-side download that may be rejected by staging HTTP authentication.

= Can I upload a .riv file from my computer? =

Yes. Choose the file in the Library editor. The Manager validates its extension, size, upload origin, and Rive file signature, then stores it locally. URL importing remains available.

= Who can manage the Rive Library? =

Administrators and users with the Editor role can open the top-level Rive Manager admin menu and add, edit, remove, or repair Library items. Authors, Contributors, and Subscribers do not receive access by default.

== Changelog ==

= 3.0.15 =
* Moves Rive Manager to its own top-level admin menu so the screen is visible to users with the Editor role.
* Keeps the `edit_pages` capability requirement and nonce verification on every add, edit, delete, and legacy-repair action.
* Preserves the v3.0.14 loader frame handoff, unified Library data, and all existing public classes and shortcodes.

= 3.0.14 =
* Captures the last useful painted outgoing loader frame as a bounded WebP and carries it through the existing same-tab navigation handoff.
* Paints the validated frame from the destination head before body content or the restarted Rive canvas can expose the solid fallback.
* Holds the carried frame above the destination loader until its new Rive canvas produces useful branded paint, then crossfades between them.
* Rejects empty or white-cleared captures, validates destination, loader, expiry, type, and size, and falls back safely when capture or session storage fails.
* Makes the configured loader exit fade visible instead of hiding the element at the start of its opacity transition.
* Preserves fast-load suppression, viewport readiness, bounded cleanup, Editor access, and Library data.

= 3.0.13 =
* Starts a matching Page Loader from the current document when an eligible internal navigation remains pending beyond a short lead threshold.
* Hands the loader ID, destination, start time, and reveal state to the destination document through same-tab session storage.
* Keeps fast destinations suppressible while covering slow document responses before destination HTML and scripts arrive.
* Excludes modified clicks, new-tab targets, downloads, external links, and same-page anchors, and retains a bounded failure cleanup.
* Preserves adaptive visible-viewport release, Editor access, unified Library data, and existing loader settings.

= 3.0.12 =
* Adds an adaptive Page Loader grace period that suppresses the overlay completely on fast page loads.
* Waits only for images, videos, iframes, and managed Rive items intersecting the initial viewport, followed by two stable paint passes.
* Stops offscreen resources, full-window load completion, and the decorative Rive completion event from delaying the exit.
* Treats resource errors as settled and retains Maximum wait as a bounded failure release.
* Preserves Editor access, unified Library data, loader targeting, and per-load frequency behavior.

= 3.0.11 =
* Allows users with the WordPress Editor role to access and manage the Rive Library.
* Applies the same Editor-compatible capability to the settings screen and all save, delete, and legacy-repair actions.
* Retains nonce verification for every Library-changing request.

= 3.0.10 =
* Keeps the Page Loader visible until both its Rive completion signal and the destination page load have finished.
* Waits for two browser paint frames before beginning the configured exit pause and fade.
* Retains the Maximum duration as a missing-animation-event fallback and adds a bounded safety release for stalled page loads.

= 3.0.9 =
* Adds direct `.riv` file uploads to the unified Library editor while preserving URL importing.
* Validates uploaded files by extension, size, trusted HTTP upload origin, and the compiled Rive file signature.
* Stores uploaded animations locally without bundling customer files into the distributable plugin.

= 3.0.8 =
* Reserves the vertical scrollbar gutter while the Page Loader locks scrolling.
* Prevents long destination pages from shifting horizontally when the loader exits and the scrollbar returns.

= 3.0.7 =
* Paints a matched Page Loader shell in the document head before destination content can flash onscreen.
* Hands the early shell to the unified Rive loader without changing its configured fallback color, timing, targeting, or fade.
* Keeps the early shell compatible with loader priority, Session/Daily frequency, reduced-motion, and legacy-loader precedence.

= 3.0.6 =
* Removes the click-side static loader bridge that caused a frozen-loader, navigation, then restarted-animation sequence.
* Runs each matching Page Loader once on the destination page, followed by its configured exit pause and fade.

= 3.0.5 =
* Renames the public-facing plugin to KO – Rive Manager while preserving its internal plugin folder, saved Library, shortcodes, classes, and upgrade path.
* Creates a matching destination Page Loader from the unified Library in the browser when a full-page cache omits the server-rendered loader.
* Keeps the click-side navigation cover static so Session and Daily frequency settings are not consumed before the destination animation starts.

= 3.0.4 =
* Ensures selected destination pages render the full Page Loader by matching the current request path as well as the WordPress queried object.
* Prevents an inactive standalone Page Loader class from suppressing the unified loader.
* Preserves click-side loader presentation while allowing the destination animation to initialize and run for its configured duration.

= 3.0.3 =
* Makes the configured Page Loader fade duration control the actual CSS opacity transition.
* Adds an Exit pause control so the Rive animation can settle briefly before fading.
* Uses a softer ease-out curve and waits for the opacity transition to finish before removing the loader.

= 3.0.2 =
* Starts the matching Page Loader immediately when an eligible internal navigation link is clicked.
* Continues to render the loader on the destination response, covering both sides of a full-page navigation.
* Matches selected pages and posts directly by the queried WordPress object ID for reliable Divi and theme compatibility.
* Prevents a stale ResizeObserver callback from touching a Rive instance after it has been cleaned up.

= 3.0.1 =
* Restores missing Hero artboard and playback metadata from matching legacy Hero settings without overwriting Library items.
* Reads the older `ko_rh_animation_code` setting when the standalone Hero record is incomplete.
* Keeps direct-URL legacy Heroes usable when their original file was bundled inside the standalone plugin.
* Shows whether each Page Loader is automatically on or off and summarizes its targeting in the Library.
* Clarifies the Page Loader settings required for homepage display.

= 3.0.0 =
* Adds one unified Library for multiple backgrounds, heroes, and page loaders.
* Uses the assigned class as the exact unique `[ko_rive id="assigned-class"]` identifier.
* Adds Background, Hero, and Page Loader role selection and role badges.
* Adds Preload and Lazy Load selection and Library badges.
* Adds multiple page loaders with targeting, priority, frequency, timing, completion-event, and accessibility controls.
* Adds a single shared Rive runtime and one-request asset cache.
* Defers hidden and offscreen placements until needed.
* Adds fallback colors and optional poster / first-frame images.
* Copies existing Manager, Hero, and Page Loader settings without altering their original options or files.
* Preserves legacy Background and Hero shortcodes during migration.
* Prevents the unified loader from running while the standalone Page Loader plugin is active.

= 1.1.2 =
* Preloads the bundled Rive runtime, WebAssembly runtime, and locally cached Rive files.
* Preserves each target element's existing CSS background while its Rive canvas loads.
* Reveals the loaded canvas with a short opacity transition.

= 1.0.0 =
* Initial public release.
