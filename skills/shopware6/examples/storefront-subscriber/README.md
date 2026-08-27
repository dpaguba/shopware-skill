# Example: Storefront Page Subscriber

Adds a "related articles" box to the product detail page: a subscriber enriches the page struct, a Twig
override renders it.

Every file in this directory is real source, not a snippet. Copy the tree into
`custom/plugins/MyPlugin/` and run `bin/console plugin:install --activate MyPlugin`.

## Files

| File | Purpose |
|---|---|
| [composer.json](composer.json) | Plugin metadata and autoload |
| [src/MyPlugin.php](src/MyPlugin.php) | Bootstrap |
| [src/Subscriber/ProductPageSubscriber.php](src/Subscriber/ProductPageSubscriber.php) | Listens to `ProductPageLoadedEvent`, adds a page extension |
| [src/Service/RelatedArticlesService.php](src/Service/RelatedArticlesService.php) | Loads the products |
| [src/Resources/config/services.xml](src/Resources/config/services.xml) | DI wiring, `kernel.event_subscriber` tag |
| [src/Resources/views/storefront/page/product-detail/index.html.twig](src/Resources/views/storefront/page/product-detail/index.html.twig) | Template override |
| [src/Resources/snippet/en_GB/messages.en-GB.json](src/Resources/snippet/en_GB/messages.en-GB.json) | Snippets |

## Four things this example gets right

**Sales channel repository, not the plain one.** `sales_channel.product.repository` applies visibility,
availability and the pricing context of the current sales channel. `product.repository` returns products
the customer must not see and prices that ignore the customer group.

**Page extensions, not template globals.** `$page->addExtension('relatedArticles', ...)` keeps the data
addressable as `page.extensions.relatedArticles` and does not collide with core variables.

**`translated`, not raw fields.** `product.translated.name` resolves through the language inheritance
chain; `product.name` can be null on an inherited translation.

**Semantic markup.** The list is a `<ul>/<li>`. SW 6.7 converted core listings to real lists as part of
the accessibility work, and a custom block that uses `<div>` stands out to screen readers.

## Extending vs decorating

A subscriber is the right tool when you add data. When you need to change what a core service returns,
decorate it instead. When you need to change the sequence of the cart calculation, write a cart
processor. See `references/storefront.md` and `references/cart.md`.
