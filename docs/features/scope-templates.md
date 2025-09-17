# Scope Templates

Collega piani di licenza specifici a uno scope in modo che ogni prodotto esponga solo le offerte autorizzate (es. mensile, trimestrale, annuale).

## Overview

`LicenseTemplate` ora contiene direttamente il riferimento allo scope attraverso il campo `license_scope_id`. Se lo lasci `null`, il template resta globale e può essere riutilizzato da qualunque scope; altrimenti è vincolato al prodotto indicato.

```php
$scope = LicenseScope::create(['name' => 'Reporting Suite']);

$monthly = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Mensile',
    'tier_level' => 1,
    'base_configuration' => [
        'max_usages' => 3,
        'validity_days' => 30,
    ],
]);
```

Se devi spostare un template creato in precedenza (magari globale) verso uno scope, usa `LicenseScope::assignTemplate()` oppure il `TemplateService`:

```php
$scope->assignTemplate($monthly);
// oppure
app(TemplateService::class)->assignTemplateToScope($scope, $monthly);
```

## Recuperare i template dello scope

```php
$templates = $scope->templates()->active()->orderedByTier()->get();

// via servizio applicando i filtri di default sugli attivi
$templates = app(TemplateService::class)->getTemplatesForScope($scope);
```

`TemplateService::getTemplatesForScope()` restituisce solo i template attivi per impostazione predefinita; passa `false` come secondo argomento per includere anche quelli disabilitati.

## Creare licenze partendo dai template

Una volta collegato lo scope al template, puoi generare licenze coerenti con un one-liner:

```php
$license = $scope->createLicenseFromTemplate($monthly->slug, [
    'key_hash' => hash('sha256', 'customer-key'),
]);

// Il license_scope_id viene assegnato automaticamente
$license->license_scope_id === $scope->id; // true
```

`License::createFromTemplate()` copia automaticamente `license_scope_id`, configurazioni base, entitlements e feature flag dal template.

## Rimuovere o migrare un template

```php
$scope->removeTemplate($monthly);     // Rende il template di nuovo "globale"
$scope->hasTemplate($monthly);        // false
```

Per impedire che lo stesso piano venga riutilizzato in scope differenti, `assignTemplate()` lancia un'eccezione se il template è già collegato a un prodotto diverso. In tal caso duplica il template o scollegalo dallo scope originario prima di procedere.

## Cosa cambia rispetto a prima

- Non esiste più una tabella pivot dedicata: niente metadati duplicati, meno query, più semplicità.
- Il campo `group` è stato rimosso in favore di `license_scope_id`. Se ti serviva raggruppare i piani puoi usare tag/chiavi custom nel campo `meta` del template.
- I piani globali restano supportati: lascia `license_scope_id` vuoto per riutilizzarli ovunque o clonali per specializzarli su singoli prodotti.
