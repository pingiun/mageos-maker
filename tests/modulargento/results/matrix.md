# Modulargento removal matrix — profile: mageos-full — run 2026-05-03

Baseline (`mageos-full`, nothing disabled): PASS — di:compile clean.

Totals: pass=34 · fail=10 · noop=0 · composer-failed=0 · install-failed=3 · timeout=0 · configure-failed=0 · harness-error=0 · unknown=0

## Removable cleanly (34 sets)

| Set                     | Duration (s) |
|-------------------------|--------------|
| `admin-analytics`       | 158          |
| `admin-theme-m137`      | 158          |
| `adobe-stock`           | 159          |
| `automatic-translation` | 160          |
| `aws-s3`                | 167          |
| `dhl`                   | 165          |
| `fedex`                 | 165          |
| `google-marketing`      | 144          |
| `inventory`             | 119          |
| `language-de_de`        | 129          |
| `language-en_us`        | 130          |
| `language-es_es`        | 130          |
| `language-fr_fr`        | 128          |
| `language-nl_nl`        | 133          |
| `language-pt_br`        | 146          |
| `language-zh_hans_cn`   | 133          |
| `login-as-customer`     | 128          |
| `luma`                  | 131          |
| `marketplace`           | 133          |
| `multishipping`         | 130          |
| `new-relic`             | 134          |
| `order-cancellation`    | 150          |
| `page-builder`          | 155          |
| `paypal`                | 161          |
| `persistent-cart`       | 166          |
| `recaptcha`             | 165          |
| `rss`                   | 150          |
| `sample-data`           | 171          |
| `send-friend`           | 173          |
| `swagger`               | 175          |
| `two-factor-auth`       | 173          |
| `ups`                   | 186          |
| `usps`                  | 175          |
| `weee`                  | 179          |

## Blocked at di:compile — grouped by error fingerprint (10 sets, 10 groups)

### `Class "Magento\GiftMessage\Helper\Message" does not exist`

- `gift-message`  ([log](raw/gift-message.log))

### `#12 /Users/jelle/dev/projects/mageos-maker/tests/modulargento/sandboxes/instant-purchase/vendor/mage-os/framework/Console/Cli.php(129): Symfony\Component\Console\Application->doRun(Object(Symfony\Component\Console\Input\ArgvInput), Object(S`

- `instant-purchase`  ([log](raw/instant-purchase.log))

### `Class "Magento\MediaGallerySynchronizationApi\Api\SynchronizeFilesInterface" does not exist`

- `media-gallery-sync`  ([log](raw/media-gallery-sync.log))

### `Class "Magento\Msrp\Helper\Data" does not exist`

- `msrp`  ([log](raw/msrp.log))

### `Class "Magento\Newsletter\Model\SubscriberFactory" does not exist`

- `newsletter`  ([log](raw/newsletter.log))

### `Class "Magento\ProductAlert\Model\StockFactory" does not exist`

- `product-alert`  ([log](raw/product-alert.log))

### `Class "Magento\ReleaseNotification\Model\Condition\CanViewNotification" does not exist`

- `release-notification`  ([log](raw/release-notification.log))

### `#12 /Users/jelle/dev/projects/mageos-maker/tests/modulargento/sandboxes/reviews/vendor/mage-os/framework/Console/Cli.php(129): Symfony\Component\Console\Application->doRun(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\Co`

- `reviews`  ([log](raw/reviews.log))

### `Class "Magento\Swatches\Helper\Media" does not exist`

- `swatches`  ([log](raw/swatches.log))

### `#12 /Users/jelle/dev/projects/mageos-maker/tests/modulargento/sandboxes/wishlist/vendor/mage-os/framework/Console/Cli.php(129): Symfony\Component\Console\Application->doRun(Object(Symfony\Component\Console\Input\ArgvInput), Object(Symfony\C`

- `wishlist`  ([log](raw/wishlist.log))

## install-failed (3 sets)

- `bundle` — `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mageos_bundle.catalog_product_bundle_selection' doesn't exist setup:install [--backend-frontname BACKEND-FRONTNAME] [--remote-storage-driver REMOTE-STORAGE-DRIVER] [--remote-storag`  ([log](raw/bundle.log))
- `downloadable` — `Call Stack: 65.1326 160309144 1. Magento\Framework\DB\Adapter\Pdo\Mysql->__destruct() /Users/jelle/dev/projects/mageos-maker/tests/modulargento/sandboxes/downloadable/vendor/mage-os/framework/DB/Adapter/Pdo/Mysql.php:0 65.1326 160309144 2.`  ([log](raw/downloadable.log))
- `grouped` — `Constant "\Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE" is not defined. setup:install [--backend-frontname BACKEND-FRONTNAME] [--enable-debug-logging ENABLE-DEBUG-LOGGING] [--enable-syslog-logging ENABLE-SYSLOG-LOGGING] [-`  ([log](raw/grouped.log))
