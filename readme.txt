=== Opti Pict ===
Contributors: optipict
Tags: images, webp, performance, media, seo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convertit localement les JPEG et PNG de la médiathèque en WebP et affiche le gain réellement obtenu.

== Description ==

Opti Pict propose un parcours simple :

1. analyser les images sans les modifier ;
2. confirmer explicitement la conversion ;
3. consulter le poids et le temps de transfert économisés.

Les originaux ne sont jamais supprimés. Une restauration est disponible depuis l’écran Média > Opti Pict. La conversion WebP est entièrement locale et n’envoie aucun fichier à un service externe.

Les URLs des blocs Image existants sont adaptées au moment de l’affichage, sans modifier le contenu enregistré dans les articles.

Le module Référencement peut utiliser l’API OpenAI pour proposer un texte alternatif, un titre de média et un identifiant lisible à partir de l’image et de son contexte sur le site. Chaque proposition doit être validée avant application.

L’utilisation de l’IA est facultative. Elle nécessite une clé API et une facturation OpenAI API distinctes. Aucune clé commune n’est incluse dans l’extension.

== Service externe ==

Le module facultatif Référencement communique avec l’API OpenAI uniquement lorsqu’un administrateur clique sur « Proposer avec l’IA ».

Pour produire la proposition, Opti Pict transmet une version réduite de l’image, le nom et la langue du site, le nom du fichier, les métadonnées actuelles et de courts extraits des contenus publiés où l’image est utilisée. Ces données sont envoyées directement depuis le site WordPress vers https://api.openai.com/v1/responses. Elles ne transitent pas par un serveur appartenant à Opti Pict.

Le stockage applicatif de la réponse est demandé avec `store: false`. Le traitement reste soumis aux règles d’OpenAI :

* Conditions d’utilisation : https://openai.com/policies/terms-of-use/
* Politique de confidentialité : https://openai.com/policies/privacy-policy/
* Contrôles des données API : https://developers.openai.com/api/docs/guides/your-data

== Sécurité des clés ==

Une clé saisie dans l’administration est chiffrée avant son stockage en base de données et n’est jamais renvoyée au navigateur. Pour une installation administrée, il est recommandé de définir `OPTI_PICT_OPENAI_API_KEY` dans `wp-config.php`, idéalement à partir d’une variable d’environnement.

Ne placez jamais une vraie clé API dans le code de l’extension, dans un dépôt Git ou dans une capture d’écran publique. Chaque installation doit utiliser sa propre clé ou le connecteur OpenAI configuré dans WordPress.

== Installation ==

1. Activez Opti Pict dans l’administration WordPress.
2. Ouvrez Média > Opti Pict.
3. Lancez l’analyse puis confirmez l’optimisation.

== Changelog ==

= 0.2.1 =
* Durcissement de la validation des clés API et des fichiers générés.
* Protection contre les collisions de noms de fichiers WebP.
* Réduction du contexte envoyé et protection contre les instructions présentes dans les contenus.
* Documentation du service externe et préparation à une publication publique.

= 0.2.0 =
* Ajout des propositions SEO et accessibilité assistées par IA.
* Validation humaine et sauvegarde avant modification des métadonnées.

= 0.1.0 =
* Première version.
