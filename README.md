# Glissplus_Worldline — Module de paiement Worldline Direct (CAWL) pour Magento 1.x

Module de paiement pour encaisser en ligne via **Worldline Direct (CAWL — Crédit
Agricole / Worldline), API v2**, en **Hosted Checkout** : le client est redirigé
vers la page de paiement hébergée par Worldline (moindre périmètre PCI-DSS, aucune
donnée carte ne transite par Magento).

> ⚠️ Ce dépôt cible **Magento 1.x**. Magento 1 est en fin de vie (plus de
> correctifs de sécurité officiels). À terme, une migration vers Magento 2 /
> Adobe Commerce est recommandée.

---

## Fonctionnement

```
Checkout Magento ──▶ commande "pending_payment"
        │
        ▼
worldline/payment/redirect ──▶ POST /v2/{merchantId}/hostedcheckouts
        │                        (création de la session, signature GCS v1HMAC)
        ▼
Page hébergée Worldline (branding via "variant")
        │
        ├──▶ worldline/payment/return  (retour client — affichage best-effort)
        │
        └──▶ worldline/webhook         (source de vérité du statut, asynchrone)
                     │
                     ▼
        Mise à jour commande : processing / facture / annulation
```

- **Le webhook est la source de vérité.** Le retour client ne sert qu'à rediriger
  l'acheteur vers la bonne page ; le statut définitif est appliqué par le webhook
  (signé et vérifié à temps constant).
- Montants envoyés en **unités mineures** (centimes).
- **directSale** = autorisation + capture ; **finalAuthorization** = autorisation
  seule (capture ultérieure à la création de la facture en admin).

## Authentification API (GCS v1HMAC)

Chaque requête est signée :

```
stringToSign = METHOD\n + ContentType\n + Date(RFC1123 GMT)\n + path\n
signature    = base64( HMAC-SHA256( apiSecret, stringToSign ) )
En-tête      : Authorization: GCS v1HMAC:{apiKeyId}:{signature}
               Date: <RFC1123 GMT>   (même valeur que dans la signature)
```

Aucun en-tête `x-gcs-*` personnalisé n'est envoyé, le bloc d'en-têtes canonisés
est donc vide.

## Hôtes Worldline

| Environnement | Hôte                                                    |
|---------------|---------------------------------------------------------|
| Test          | `payment.preprod.direct.worldline-solutions.com`        |
| Live          | `payment.direct.worldline-solutions.com`                |

---

## Installation

### Option A — Copie directe (déploiement maîtrisé)

Copier l'arborescence `app/` dans la racine Magento :

```
app/etc/modules/Glissplus_Worldline.xml
app/code/community/Glissplus/Worldline/...
app/locale/fr_FR/Glissplus_Worldline.csv
```

Puis vider le cache :

```bash
php -f shell/indexer.php reindexall   # si nécessaire
rm -rf var/cache/*
```

et dans l'admin : **System > Cache Management > Flush Magento Cache**.

### Option B — modman / composer

Un fichier `modman` et un `composer.json` (`type: magento-module`) sont fournis
pour un déploiement via [modman](https://github.com/colinmollenhour/modman) ou
`magento-hackathon/magento-composer-installer` vers une racine Magento externe.

---

## Configuration

**Admin > System > Configuration > Sales > Payment Methods > Worldline Direct - CAWL**

| Champ | Description |
|-------|-------------|
| Enabled | Active le mode de paiement |
| Title | Libellé affiché au checkout |
| Environment | `Test (preprod)` ou `Live (production)` |
| Merchant ID / PSPID | Identifiant marchand CAWL |
| API Key Id / API Secret | Clé API (le secret est **chiffré** en base) |
| Webhook Key Id / Webhook Secret | Pour vérifier la signature des webhooks (secret **chiffré**) |
| Hosted Page Variant | Nom du template du portail CAWL pour le branding. **GLISSPLUS : `SimplifiedCustomPaymentPage`**. Vide = page Worldline par défaut. |
| Payment Action | `Authorize only` ou `Authorize and Capture` |
| New Order Status | Statut appliqué quand le paiement est confirmé |
| Applicable Countries | Restriction pays |
| Sort Order | Ordre d'affichage |
| Debug Log | Journalise dans `var/log/worldline.log` |

> Les identifiants réels (PSPID, clés/secrets API et webhook) se récupèrent dans
> le **portail CAWL** et se saisissent **uniquement dans l'admin Magento** — ils
> ne sont jamais versionnés dans Git.

## Déclaration du webhook (portail CAWL)

URL à déclarer :

```
https://<votre-domaine>/worldline/webhook
```

Récupérer la **clé** et le **secret webhook** générés par le portail et les
renseigner dans la configuration du module. La signature reçue
(`X-GCS-Signature`) est recalculée côté Magento et comparée à temps constant ;
toute requête non signée ou mal signée renvoie `400`.

---

## Opérations admin

- **Facture (capture)** : en mode *Authorize only*, créer une facture déclenche
  la capture via l'API Worldline. En mode *Authorize and Capture*, la facture est
  générée automatiquement à réception du webhook de capture.
- **Avoir (remboursement)** : créer un credit memo appelle l'endpoint `refund`.
- **Annulation / void** : annule une autorisation non capturée (`cancel`).

---

## Sécurité — rappels importants

- **Révoquer / changer immédiatement** tout identifiant serveur partagé en clair
  (ex. root + mot de passe transmis en chat).
- Désactiver l'authentification SSH par mot de passe et `PermitRootLogin no` ;
  passer par **clés SSH + utilisateur sudo**.
- **Aucun développement en direct sur le serveur en root** : flux Git → PR →
  déploiement maîtrisé.
- Les **secrets PSP** se saisissent dans l'admin Magento (chiffrés), **jamais
  commités**.

## Structure du module

```
app/etc/modules/Glissplus_Worldline.xml
app/code/community/Glissplus/Worldline/
├── etc/
│   ├── config.xml
│   └── system.xml
├── Helper/Data.php
├── Model/
│   ├── Standard.php              # méthode de paiement (redirect + capture/refund/void)
│   ├── Api/Client.php            # client API v2 signé GCS v1HMAC
│   └── Source/
│       ├── Environment.php
│       └── PaymentAction.php
├── Block/Form.php                # formulaire checkout (libellé seul)
└── controllers/
    ├── PaymentController.php      # redirect / return / cancel
    └── WebhookController.php      # webhook signé (source de vérité)
app/locale/fr_FR/Glissplus_Worldline.csv
```
