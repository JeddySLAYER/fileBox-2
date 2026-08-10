# FileBox — Scénarios de tests (couverture complète)

Document de recette manuelle (et base pour automatisation).  
**Rôles de référence :** Administrateur · Direction · Responsable département · Chef de projet · Collaborateur · Invité  

**Légende :** ✅ nominal · ⚠️ limite / edge · ❌ erreur attendue  

Pour chaque scénario : **prérequis → actions → résultat attendu**.

---

## 0. Préparation environnement

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| ENV-01 | ✅ | `migrate --seed`, serve backend + frontend, compte admin | Login OK |
| ENV-02 | ✅ | `GEMINI_API_KEY` renseignée | Boutons IA OK |
| ENV-03 | ❌ | `GEMINI_API_KEY` vide | Résumer / OCR → message clé manquante (422) |
| ENV-04 | ⚠️ | Mail SMTP invalide | Création user OK ; message mail non envoyé (sans afficher le MDP) |

---

## 1. Authentification & sécurité

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| AUTH-01 | ✅ | Login email/mdp valides | Token + redirection dashboard / change-password |
| AUTH-02 | ❌ | Mauvais mot de passe | 422 ; journal `auth.login_failed` |
| AUTH-03 | ❌ | Compte `is_active=false` | Refus de connexion |
| AUTH-04 | ❌ | 5 échecs consécutifs | Verrouillage ~15 min (422) |
| AUTH-05 | ✅ | 6ᵉ tentative après délai | Login possible à nouveau |
| AUTH-06 | ✅ | Logout | Token invalidé ; 401 sur `/auth/me` |
| AUTH-07 | ✅ | Changement de mot de passe (forcé) | `must_change_password=false` ; anciens tokens révoqués |
| AUTH-08 | ❌ | Accès API sans changer MDP temporaire | 403 + redirection change-password |
| AUTH-09 | ❌ | MDP temporaire expiré (>24h) | Login refusé |
| AUTH-10 | ❌ | Accès route protégée sans token | 401 |
| AUTH-11 | ❌ | Inscription publique | Absente / impossible |

---

## 2. Utilisateurs

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| USR-01 | ✅ | Admin crée un utilisateur + rôles | Compte créé ; e-mail MDP temporaire (si mail OK) |
| USR-02 | ✅ | Admin réinitialise le MDP | Message succès ; pas d’affichage du MDP à l’écran |
| USR-03 | ✅ | Admin désactive / soft-delete | User inactif ou corbeille soft |
| USR-04 | ✅ | Réutiliser l’e-mail après soft-delete | Création possible (e-mail libéré) |
| USR-05 | ❌ | Collaborateur ouvre `/users` | Accès refusé |
| USR-06 | ❌ | Admin se supprime lui-même | 422 |
| USR-07 | ✅ | Filtrer / rechercher users | Liste filtrée |
| USR-08 | ✅ | Modifier nom, département, rôles | Persistance OK |

---

## 3. Rôles & permissions

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| ROL-01 | ✅ | Créer un rôle custom + cocher permissions | Sync OK |
| ROL-02 | ✅ | Modifier les permissions d’un rôle | Effet immédiat sur un user de ce rôle |
| ROL-03 | ❌ | Supprimer un rôle système (admin, etc.) | 422 |
| ROL-04 | ❌ | Supprimer un rôle encore assigné | 422 |
| ROL-05 | ❌ | Collaborateur gère les rôles | 403 |
| ROL-06 | ⚠️ | User multi-rôles | Union des permissions |

---

## 4. Départements & projets

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| ORG-01 | ✅ | CRUD département | OK |
| ORG-02 | ✅ | CRUD projet + manager | OK |
| ORG-03 | ✅ | Sync membres projet | Membres listés / sauvegardés |
| ORG-04 | ❌ | Collaborateur CRUD org | 403 |
| ORG-05 | ✅ | Chef de projet voit son projet | Accès détail OK |
| ORG-06 | ❌ | User hors projet tente manage | 403 |

---

## 5. Dossiers & explorateur

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| FLD-01 | ✅ | Créer dossier racine puis enfant | Arborescence OK |
| FLD-02 | ✅ | Naviguer explorateur, lister docs | Contenu du dossier |
| FLD-03 | ✅ | Favori dossier (★ liste + dossier courant) | Apparait dashboard / favoris |
| FLD-04 | ✅ | Supprimer dossier non vide (enfants ou docs) | Soft-delete récursif → corbeille |
| FLD-05 | ❌ | Déplacer dossier sous lui-même / descendant | 422 cycle |
| FLD-06 | ✅ | Soft-delete dossier vide → corbeille → restore | Restauré |
| FLD-07 | ❌ | Invité sans accès | Dossier invisible / 403 |

---

## 6. Documents — cycle de vie

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| DOC-01 | ✅ | Upload PDF dans un dossier | Création + version v1 + référence |
| DOC-02 | ✅ | Upload `.txt` | `is_editable=true` auto ; onglet Édition |
| DOC-03 | ✅ | Upload `.docx` / `.pdf` | `is_editable=false` ; pas d’édition en ligne |
| DOC-04 | ❌ | Forcer `is_editable` à la création | Ignoré ; dérivé de l’extension |
| DOC-05 | ✅ | Modifier métadonnées (titre, type, tags) | Sauvegardé |
| DOC-06 | ❌ | Modifier doc `en_validation` ou `archive` | 422 |
| DOC-07 | ✅ | Déplacer vers autre dossier | `folder_id` mis à jour |
| DOC-08 | ✅ | Archiver / désarchiver | Statuts OK |
| DOC-09 | ✅ | Soft-delete → trash → restore | Retour brouillon |
| DOC-10 | ✅ | Publier un doc `valide` | Statut `publie` + notif concernés |
| DOC-11 | ❌ | Publier un brouillon | 422 |
| DOC-12 | ❌ | Créer sans `documents.create` | 403 |

---

## 7. Versions, contenu, preview

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| VER-01 | ✅ | Réupload nouvelle version | v2 créée ; v1 `is_locked` |
| VER-02 | ✅ | Édition en ligne `.txt` | Nouvelle version ; contenu à jour |
| VER-03 | ❌ | PUT content sur PDF | 422 `is_editable` |
| VER-04 | ✅ | Comparer 2 versions texte | Diff lignes |
| VER-05 | ⚠️ | Comparer 2 PDF | Métadonnées ; pas de diff contenu |
| VER-06 | ✅ | Download / preview PDF ou image | Fichier / aperçu |
| VER-07 | ✅ | URL signée preview | Accès sans Bearer tant que signature valide |
| VER-08 | ❌ | URL signée expirée / altérée | 403 |
| VER-09 | ✅ | Réupload `.txt` sur un ancien `.docx` | Devient éditable |

---

## 8. Workflows & validations

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| WF-01 | ✅ | Créer workflow 2 étapes (rôles responsables) | Steps OK |
| WF-02 | ✅ | Doc projet public : proposer | Statut `propose` ; notif manager / workflows.manage |
| WF-03 | ✅ | Admin démarre workflow + délais (10h / 1j) | `en_validation` ; `due_at` sur 1ʳᵉ étape |
| WF-04 | ✅ | Approuver étape 1 | Étape 2 active ; son `due_at` calculé |
| WF-05 | ✅ | Approuver toutes les étapes | Doc `valide` |
| WF-06 | ❌ | Rejet sans commentaire | 422 |
| WF-07 | ✅ | Rejet avec motif | Doc `rejete` ; auteur notifié |
| WF-08 | ✅ | Demande de correction | Doc `brouillon` ; reprise possible |
| WF-09 | ✅ | Après correction : restart → propose → start | Nouveau cycle |
| WF-10 | ❌ | Doc personnel (`project_id` null) : start workflow | 422 |
| WF-11 | ❌ | Valider hors étape courante | 422 |
| WF-12 | ❌ | Collaborateur non responsable tente d’agir | 403 |
| WF-13 | ✅ | Responsable d’étape (user/rôle) agit sans `validations.act` global | OK si nommé sur l’étape |
| WF-14 | ✅ | Superviseur (`validations.act` / `workflows.manage`) agit | OK |
| WF-15 | ⚠️ | Workflow inactif / sans étapes | Start 422 |
| WF-16 | ✅ | Page `/proposed` | Liste des propositions pour qui de droit |
| WF-17 | ⚠️ | Type `requires_workflow` | Suggestion UI seulement ; pas de blocage |

---

## 9. Accès / partages

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| ACL-01 | ✅ | Partager doc (view/download) à un invité | Invité voit doc + download |
| ACL-02 | ✅ | Partager dossier (view) | Enfants / docs hérités selon règles |
| ACL-03 | ✅ | Accès avec `ends_at` futur | Actif jusqu’à échéance |
| ACL-04 | ✅ | Cron / commande révocation expirés | Accès retiré |
| ACL-05 | ✅ | Notif accès accordé / révoqué / bientôt expiré | Une seule notif deadline |
| ACL-06 | ❌ | S’auto-accorder un accès | 422 |
| ACL-07 | ❌ | Grantor sans droit share | 403 |
| ACL-08 | ✅ | Page Mes partages | Liste des accès reçus |
| ACL-09 | ❌ | Invité tente d’accéder à un doc non partagé | 403 / absent de la liste |

---

## 10. Commentaires

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| CMT-01 | ✅ | Commenter un doc | Créé ; notif auteur/propriétaire |
| CMT-02 | ✅ | Répondre à un commentaire | Notif auteur du parent |
| CMT-03 | ✅ | Éditer / supprimer son commentaire | OK |
| CMT-04 | ❌ | Parent d’un autre document | 422 |
| CMT-05 | ❌ | Sans `comments.create` | 403 |

---

## 11. Favoris

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| FAV-01 | ✅ | ★ document | Liste favoris + dashboard |
| FAV-02 | ✅ | ★ dossier (explorateur) | Idem |
| FAV-03 | ✅ | Retirer favori | Disparu |
| FAV-04 | ❌ | Favori sans droit de vue | 403 |

---

## 12. Recherche

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| SCH-01 | ✅ | Recherche texte ≥2 car. | Docs match titre/réf/desc/tags |
| SCH-02 | ✅ | Filtres projet / département / statut / type / confidentialité | Résultats restreints |
| SCH-03 | ✅ | Filtres seuls (sans texte) | Liste filtrée |
| SCH-04 | ✅ | Dossiers matchés | Section dossiers |
| SCH-05 | ❌ | User sans view ni accès | 403 |
| SCH-06 | ⚠️ | Invité | Uniquement docs accessibles |

---

## 13. Dashboard / pilotage

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| DSH-01 | ✅ | Admin / Direction | KPIs globaux + validations + activité + favoris |
| DSH-02 | ✅ | Responsable / Chef projet | KPIs scopés |
| DSH-03 | ✅ | Collaborateur | Home : à valider, à reprendre, partagés, commentaires, favoris, récents |
| DSH-04 | ✅ | Validations bloquées / en retard (`due_at`) | Compteur + liste |
| DSH-05 | ✅ | Lien validation → fiche doc | Actions approve/reject disponibles |

---

## 14. Notifications

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| NTF-01 | ✅ | Recevoir notif importante | Badge + liste |
| NTF-02 | ✅ | Marquer lu / tout lu | Compteur à 0 |
| NTF-03 | ⚠️ | Commentaire | Notif ciblée (pas de spam global) |
| NTF-04 | ✅ | Publication | Notif auteur + accès (pas l’acteur) |
| NTF-05 | ❌ | Voir les notifs d’un autre user | Impossible |

---

## 15. Assistant IA (Gemini)

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| AI-01 | ✅ | Résumer un `.txt` | `summary` rempli |
| AI-02 | ✅ | Analyser un PDF / image | `ai_analysis` rempli |
| AI-03 | ✅ | OCR scan PDF/image | `ocr_text` + éventuel résumé |
| AI-04 | ❌ | OCR sur `.docx` | 422 format non supporté |
| AI-05 | ❌ | Fichier > ~12 Mo | 422 trop volumineux |
| AI-06 | ❌ | Clé API absente | 422 message clair |
| AI-07 | ❌ | Sans droit de vue sur le doc | 403 |
| AI-08 | ⚠️ | Relancer résumé | Écrase l’ancien résumé |

---

## 16. Types de documents & tags

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| TYP-01 | ✅ | CRUD type + workflow par défaut | OK |
| TYP-02 | ⚠️ | `requires_workflow=true` | Conseillé UI ; création sans auto-start |
| TAG-01 | ✅ | Créer tags + sync sur doc | Tags visibles fiche / recherche |
| TAG-02 | ❌ | Sync tags sans droit update doc | 403 |

---

## 17. Paramètres, backups, journal

| ID | Type | Scénario | Résultat attendu |
|----|------|----------|------------------|
| SET-01 | ✅ | Modifier settings bulk | Persistance |
| SET-02 | ❌ | Collaborateur settings | 403 |
| BKP-01 | ✅ | Créer sauvegarde | Status completed + log |
| BKP-02 | ✅ | Download backup | Fichier téléchargé |
| BKP-03 | ⚠️ | Restore (env de test uniquement) | Données restaurées |
| ACT-01 | ✅ | Admin consulte activity logs | Liste filtrable |
| ACT-02 | ✅ | Responsable : logs scopés | Pas le global |
| ACT-03 | ❌ | Collaborateur activity | 403 |

---

## 18. Parcours métier bout-en-bout (chaînés)

### E2E-A — Cycle document projet
1. Collaborateur crée un PDF dans un dossier projet  
2. Propose → Admin démarre workflow avec délais  
3. Validateur 1 approuve → Validateur 2 rejette **sans** commentaire → ❌  
4. Validateur 2 rejette **avec** motif → auteur corrige → restart → propose → start  
5. Toutes étapes OK → publish → notifs  

### E2E-B — Invité externe
1. Admin partage un doc (view+download) à un invité  
2. Invité se connecte (si compte) / voit Mes partages  
3. Download OK ; liste globale docs limitée  
4. Révocation → accès coupé  

### E2E-C — Édition texte + IA
1. Upload `note.txt` → éditable  
2. Édition en ligne → v2  
3. Résumer (Gemini) → summary affiché  
4. Remplacer par scan PNG → OCR  

### E2E-D — Sécurité compte
1. Créer user → e-mail MDP temporaire  
2. Login → forcé change-password  
3. 5 mauvais MDP sur un autre compte → lockout  

### E2E-E — Pilotage
1. Laisser une validation pending avec `due_at` passé  
2. Dashboard admin : « En retard »  
3. Collaborateur : « À reprendre » si rejeté  

---

## 19. Matrice rôles (smoke rapide)

Pour chaque rôle, vérifier **accès UI + une action clé** :

| Rôle | Doit pouvoir | Ne doit pas |
|------|--------------|-------------|
| Admin | Tout | — |
| Direction | Dashboard global, valider | Gérer users/roles (selon seed) |
| Responsable | KPIs département, archives | Settings/backups |
| Chef projet | Workflows projet, start WF | Users système |
| Collaborateur | Créer docs, commenter, favoris | Users, settings, activity global |
| Invité | Uniquement partages reçus | Explorer global, CRUD |

---

## 20. Checklist non-régression technique

| ID | Vérification |
|----|----------------|
| TECH-01 | `php artisan test` → 100 % vert |
| TECH-02 | `npm run lint` + `npm run build` OK |
| TECH-03 | Pas de MDP temporaire dans les réponses JSON / toasts |
| TECH-04 | Routes favorites, AI, propose, publish présentes (`route:list`) |
| TECH-05 | Cron / scheduler : `accesses:revoke-expired`, `notifications:access-deadlines` |

---

## Priorisation recommandée (ordre d’exécution)

1. **P0** — AUTH, DOC cycle, ACL invité, VALIDATIONS (rejet/commentaire), E2E-A  
2. **P1** — Versions/édition, Dashboard, Notifications, Recherche, Favoris  
3. **P2** — IA Gemini, Backups, Settings, Activity, Org  
4. **P3** — Edges (cycles dossiers, lockout, URL signée, délais SLA)

---

*Généré pour FileBox GED — à utiliser en recette manuelle et comme cahier de tests de soutenance.*
