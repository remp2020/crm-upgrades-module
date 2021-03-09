# CRM Upgrades Module

Module provides a way to upgrade user's subscriptions. It utilizes current subscription price, target subscription price, and number of remaining days of user's subscription to calculate "credit" which is used to provide different ways of upgrade.

## Installation

We recommend using Composer for installation and update management. To add CRM Upgrades extension to your [REMP CRM](https://github.com/remp2020/crm-skeleton/) application use following command:

```bash
composer require remp/crm-upgrades-module
```

Enable installed extension in your `app/config/config.neon` file:

```neon
extensions:
	# ...
	- Crm\UpgradesModule\DI\UpgradesModuleExtension
```

## Configuration

### Prerequisites

Upgrades expect you to configure default subscription type for each combination of content access and subscription length. This way the module knows, that if you want to upgrade to subscription type with content access `content_access_AA`, it's the default one.

The setting is not available in the UI, so you need to seed it manually. You can use following snippet to make sure, there's only one default subscription type for each content access / length combination.

<details>

<summary>Validation snippet</summary>

```php
private function validateDefaultSubscriptionTypes()
{
    $defaultSubscriptionTypes = $this->subscriptionTypesRepository->getTable()
        ->where(['default' => true])
        ->fetchAll();

    $contentAccessCheck = [];
    foreach ($defaultSubscriptionTypes as $subscriptionType) {
        $contentAccess = [];
        foreach ($subscriptionType->related('subscription_type_content_access') as $stca) {
            $contentAccess[$stca->content_access->name] = $stca->content_access->name;
        }
        sort($contentAccess);
        $hash = md5(implode('', $contentAccess) . $subscriptionType->length);
        $contentAccessCheck[$hash][] = $subscriptionType->code;
    }

    $duplicates = [];
    foreach ($contentAccessCheck as $hash => $subscriptionTypes) {
        if (count($subscriptionTypes) > 1) {
            $duplicates[] = $subscriptionTypes;
        }
    }
    return $duplicates;
}
```

</details>

Alternatively you could set the target subscription type explicitly at the `upgrade_options.subscription_type` column, however this would cause a lot of duplication and is not generally recommended.

### Upgraders

Module currently provides 4 default upgraders to use:

- `paid_recurrent`. Calculates the amount of money that user needs to pay to upgrade to better subscription without changing the billing cycle. Charge is made directly.
- `free_recurrent`. Changes the subscription type configured on the recurring payment. Upgrade is made free of charge at the moment of upgrade, higher amount will be charged at the next billing cycle.
- `paid_extend`. Calculates the discount for fully new subscription. End time of original subscription is ignored, original subscription is stopped and remaining days are used as a credit to discount the upgraded subscription price.
- `short`. Calculates the credit user has available and uses it to calculate number of days of higher-tier subscription user could have. Upgrade "shortens" the original subscription and upgrades it.

You can implement your own upgrader by following `Crm\UpgradesModule\Upgrade\UpgraderInterface` and then registering it in applicatino configuration:

```neon
services:
	upgraderFactory:
		setup:
			- registerUpgrader(\Crm\FooModule\Models\Upgrades\FooUpgrade())
```

### Upgrade scenarios

At this moment there's no UI to configure the upgrades within CRM admin. You need to seed your configuration from within your internal modules.

#### Upgrade options

Options define which upgrader can be used for which kind of content-access-based upgrade. The two important columns are:

- `type`. This is referencing `UpgraderInterface::getType()` return value.
- `config`. Object defining the conditions of upgrade (described below).
  ```json5
  {
    /*
     * Required. Array of strings. Content access you want to upgrade to. UpgradesModule will search for the default
     * subscription type with the same length, and content access of:
     *
     *   - currently active subscription type, and
     *   - content_access_CC (or whatever is set within "require_content" property
     * 
     * When module finds all available options, it calculates profitability of upgrade to each one of them. The most
     * profitable is offered to user as an option to upgrade.
     */
    "require_content": ["content_access_CC"],
  
    /*
     * Optional. Array of strings. Content access you don't want to upgrade to. UpgradesModule will find the default
     * subscription type with the same length, and content access defined by the "require_content" property, but
     * without ones defined within this property ("content_access_BB").
     * 
     * Since upgrades module always tries to find subscription types with the same content access (plus the ones required)
     * as the current subscription, you can use this property to achieve following:
     *
     *   - Current subscription content access: ["content_access_AA, "content_access_BB"]
     *   - Content access we want to upgrade to: ["content_access_AA", "content_access_CC"]
     */
    "omit_content": ["content_access_BB"],
  
    /*
     * Optional. Number. Defines fixed amount of monthly price raise. By default the module uses price of the target
     * subscription type as a variable for calculations. You can override that and set a monthly fixed raise of price
     * instead. If user is paying 10eur/month and want's to upgrade to 20eur/month subscription, this setting would
     * force all calculations to "see" that the price of upgraded subscription type is 15eur/month (10+5), not 20eur.
     */  
    "monthly_fix": 5,
  
    /*
     * Optional. Some upgrade options (like the one with "monthly_fix") probably shouldn't be available for everyone.
     * You may enforce the limitation by requiring special tags at the landing page for this special offer.
     * The upgrade option will only be available if you request the list of available upgraders with this special tag:
     *
     *   $this->availableUpgraders->all($this->getUser()->id, $contentAccess, ["foo"]);
     */
    "require_tags": ["foo"]
  }
  ```
  
#### Upgrade schemas

Schemas have only one purpose: to bundle multiple upgrade options logically together.

Module needs to link each subscription type with upgrade options that it can use. To prevent linking each subscription type with *N* upgrade options, options are grouped within a schema and subscription type is linked to this single schema.

The link is made in `subscription_type_upgrade_schemas` table and it needs to be defined explicitly for each subscription type you want to be upgradeable.

#### Example

If you want to allow upgrade to content access `foo` by shortening the subscription, you would:

- Insert new `upgrade_option` record with values:
    - `type`: `short`
    - `config`: `{"require_content": ["foo"]}`
- Link it to some `upgrade_schema` record.
- Link all the subscription types you want to allow the upgrade from to this `upgrade_schema` record.
- Make sure there's a single subscription type with content access `foo` for each standard length of your subscription types.

## Using module

Module has a default presentation layer displaying all the default available upgrade scenarios for currently logged user at `:Upgrades:Upgrade:default` route.

It's recommended to make a custom implementation of the presenter for special offer upgrades due to the use of `require_tags` configuration option.