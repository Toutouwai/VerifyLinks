# Verify Links

Periodically verifies that external links are working and not leading to an error page.

Requires the following core modules to be installed:
* PagePaths
* LazyCron

## How it works

The module identifies links on a page when the page is saved and stores the URLs in a database table. For the purposes of this module a "link" is an external URL in any of the following...

* FieldtypeURL fields, and fields whose Fieldtype extends it (e.g. ProFields Verified URL)
* URL columns in a ProFields Table field
* URL subfields in a ProFields Combo field
* URL subfields in a ProFields Multiplier field

...and external `href` attributes from `<a>` tags in any of the following...

* Textarea fields where the "Content Type" is "Markup/HTML" (e.g. CKEditor and TinyMCE fields)
* CKEditor and TinyMCE columns in a ProFields Table field
* CKEditor and TinyMCE subfields in a ProFields Combo field

The link URLs stored in the database table are then checked in batches via LazyCron and the response code for each URL is recorded.

## Configuration

![vl-2](https://github.com/user-attachments/assets/86cc260f-8179-4d74-82ff-5eae0c23b48b)

On the main module config screen you can define settings that determine the link verification rate. You can choose the frequency that the LazyCron task will execute and the number of links that are verified with each LazyCron execution. The description line in this section informs you approximately how often all links in the site will be verified based on the number of links currently detected and the settings you have chosen.

The module verifies links using `curl_multi_exec` which is pretty fast in most cases so if your site has a lot of links you can experiment with increasing the number of links to verify during each LazyCron execution.

You can also set the timeout for each link verification and customise the list of user agents if needed.

![vl-3](https://github.com/user-attachments/assets/11917ecf-1ca7-4e50-adb4-b7e8fc44187d)

In the Process module config there's a field allowing you to exclude URLs that start with a given string. This only applies to the "Error responses only" listing, and can be useful to avoid seeing false-positive error statuses for domains that you know provide inaccurate responses (more about this below).

## Usage

Visit Setup > Verify Links to view a paginated table showing the status of the links that have been identified in your site.

![verify-links-1](https://github.com/user-attachments/assets/d383bf1b-200f-4efa-8efd-63f551581a2c)

The table rows are colour-coded according to the response code:

* Potentially problematic response = red background
* Redirect response = orange background
* OK response = green background
* Link has not yet been checked = white background

Where you see a 403 response code it's recommended to manually verify the link by clicking the URL to see if the page loads or not before treating it as a broken link. That's because some servers have anti-scraping firewalls that issue a 403 Forbidden response to requests from IP ranges that correspond to datacentres rather than to individual ISP customers and this will cause a "false positive" as a broken link.

For each link the "Page" column contains a link to edit the page and the "View" column contains a link to view the page on the front-end.

You can use the "Column visibility" dropdown to include a "Redirect" column in the table, which shows the redirect URL where this is available.

You can use the "Custom Search Builder" to filter the table by particular column values, e.g. for a particular response code.

To see only links that have an error response code (400 or higher, or 0), use the flyout menu to visit Setup > Verify Links > Error responses only.

![verify-links-2](https://github.com/Toutouwai/VerifyLinks/assets/1538852/dc45a270-0e71-4c38-8c02-dff9d43dd56c)

### For those who can't wait

The module identifies links as pages are saved and verifies links on a LazyCron schedule. If you've installed the module on an existing site and you don't want to wait for this process to happen organically you can use the ProcessWire API to save pages and verify links _en masse_.

```php
// Save all non-admin, non-trashed pages in the site
// If your site has a very large number of pages you may need to split this into batches
$items = $pages->find("has_parent!=2|7, template!=admin, include=all");
foreach($items as $item) {
    $item->of(false);
    $item->save();
}
```

```php
// Verify the given number of links from those that VerifyLinks has identified
// Execute this repeatedly until there are no more white rows in the Verify Links table
// You can try increasing $number_of_links if you like
$vl = $modules->get('VerifyLinks');
$number_of_links = 20;
$vl->verifyLinks($number_of_links);
```

### Advanced

There are hookable methods but most users won't need to bother with these:

* `VerifyLinks::allowForField($field, $page)` - Allow link URLs to be extracted from this field on this page?
* `VerifyLinks::isValidLink($url)` - Is this a valid link URL to be saved by this module?
* `VerifyLinks::extractHtmlLinks($html)` - Extract an array of external link URLs from the supplied HTML string
