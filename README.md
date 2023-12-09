# Verify Links

Periodically verifies that external links are working and not leading to an error page.

Tracy Console snippets for the initial gathering and checking of links:

```php
// Count the number of pages in the site
d($pages->coun("template!=admin, has_parent!=2, include=hidden"));
```

```php
// Save all pages
// Could add a limit and execute several times if there are a large number of pages
$items = $pages->find("template!=admin, has_parent!=2, include=hidden, sort=modified");
foreach($items as $item) {
	$item->of(false);
	$item->save();
}
```

```php
// Verify the given number of links
$vl = $modules->get('VerifyLinks');
$vl->verifyLinks(40);
```
