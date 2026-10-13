# Opti Pict

Opti Pict est une extension WordPress qui convertit localement les images JPEG et PNG en WebP ou AVIF, mesure le poids économisé et aide à améliorer les textes alternatifs et métadonnées des images.

## Fonctionnalités

- analyse de la médiathèque avant toute modification ;
- conversion WebP ou AVIF locale selon les capacités du serveur, avec conservation des originaux ;
- mesure globale et individuelle du poids économisé ;
- restauration globale ou individuelle des fichiers et métadonnées précédents ;
- remplacement à l’affichage des anciennes URL JPEG/PNG par les URL optimisées dans les balises Image du document public, y compris les en-têtes, pieds de page, widgets et constructeurs compatibles ;
- propositions facultatives de textes alternatifs, titres et noms conseillés avec l’API OpenAI, fondées sur les pages où chaque image est réellement utilisée et sur un contexte éditorial facultatif ;
- validation humaine obligatoire avant l’application d’une proposition IA.

## Installation

1. Copiez le dossier `opti-pict` dans `wp-content/plugins/`.
2. Activez **Opti Pict** dans WordPress.
3. Ouvrez **Médias → Opti Pict**.

WordPress 6.4 ou plus récent et PHP 7.4 ou plus récent sont requis. La création WebP doit être disponible dans GD ou Imagick. AVIF est proposé uniquement lorsque le serveur sait l’encoder.

Après une optimisation en production, purgez le cache de page et le CDN afin que les pages soient régénérées avec les URL optimisées.

## Configuration de l’IA

Chaque installation utilise sa propre clé OpenAI API. Aucune clé partagée n’est incluse dans ce dépôt.

La méthode recommandée consiste à fournir la clé depuis l’environnement dans `wp-config.php` :

```php
define( 'OPTI_PICT_OPENAI_API_KEY', getenv( 'OPENAI_API_KEY' ) );
```

La clé peut aussi être saisie dans l’administration. Elle est alors chiffrée au repos à partir des sels WordPress et n’est jamais renvoyée au navigateur.

Ne commitez jamais de clé réelle dans ce dépôt. Si une clé a été publiée, révoquez-la immédiatement depuis la plateforme OpenAI.

## Données envoyées

La conversion WebP/AVIF reste locale. L’API OpenAI est appelée uniquement lorsqu’un administrateur demande explicitement une proposition. Une image réduite et un contexte éditorial limité sont alors transmis directement à OpenAI avec `store: false`. Consultez [readme.txt](readme.txt) pour la déclaration complète du service externe.

## Sécurité

Les actions d’administration utilisent les capacités WordPress et des nonces. Les opérations sur les fichiers sont limitées aux images validées dans le dossier des téléversements.

Consultez [SECURITY.md](SECURITY.md) pour signaler une vulnérabilité sans l’exposer publiquement.

## Licence

GPL-2.0-or-later.
# opti-pict
