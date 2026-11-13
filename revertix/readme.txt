=== Revertix - Backup, Migration, Staging & Clone ===
Contributors: nitramix, martinvalchev
Donate link: https://nitramix.com/donate/
Tags: backup, migration, staging, clone, restore
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup, staging, migration & clone for WordPress. Back up, duplicate and move your whole site in resumable chunks - no timeouts on big sites.

== Description ==

**Revertix** is a simple, safe **WordPress backup, staging and migration plugin**. Create a staging copy of your live site, take a full backup before you change anything, and clone, duplicate or move your entire site to another host - without SQL exports, FTP juggling, or the dreaded white screen on a big site.

Whether you need a **WordPress backup plugin**, a **staging site**, or a **migration and cloning** tool to move your site to a new host or domain, Revertix does it from one dashboard - no code, no shortcodes, no separate database export.

Every heavy job (cloning, backing up, exporting, importing) runs in small, resumable chunks with a strict time budget per step. That means large sites keep going instead of dying on a PHP timeout, and a job can pick up where it left off - even if a step is interrupted.

Revertix is built to be careful with your data: before any destructive action (like restoring a backup) it automatically takes a safety backup first, and it always keeps your most recent backup so you are never left without one to restore.

= What you can do =

*   **Staging** - Create a private staging copy of your live site with one click. Open it, reset it (re-clone from live), or delete it when you are done. Staging copies are login-protected and clearly badged so you always know which environment you are in.
*   **Backups** - Take a full backup (database + files) any time. Restore, download, or delete backups from one place. A safety backup of the current site is taken automatically before every restore.
*   **Export / Import** - Package your entire site (database and files) into a single portable .zip, download it, and import it into another WordPress install to migrate or duplicate your site.
*   **Share a package** - Hand an export to someone who has no account on your site - a developer, your host's support desk - with a private download link. It lasts seven days, you can see it again or withdraw it whenever you like, and it disappears with the package.
*   **Rollback** - Roll a plugin or theme back to any earlier WordPress.org version straight from your dashboard, with an automatic safety snapshot taken first. You can also switch **WordPress itself** to an earlier release (or back up to a newer one) through WordPress' own updater, and pause automatic WordPress updates so the site stays on the version you chose.
*   **Settings** - Control safety-backup retention, staging access, email notifications, and whether Revertix removes its data on uninstall.
*   **System Info & Logs** - A built-in environment report and activity log make it easy to see what happened and to share details with support.

= Built for big sites =

Revertix never tries to do everything in a single request. Cloning, backup, export and import all run as **resumable, time-budgeted jobs** - a few seconds of work per tick - so they scale to large sites and slow hosts without hitting `max_execution_time` limits.

= Safe by default =

*   A **safety backup** is taken automatically before restoring a backup, so a restore is always reversible.
*   Revertix always keeps your **most recent backup**, so you are never one click away from having nothing to restore.
*   Staging copies are **login-protected** and visually badged, so you never mistake staging for live.

= Perfect for =

*   Creating a **WordPress staging site** to test changes safely before they go live
*   Taking a **full site backup** before an update, redesign, or risky change
*   **Migrating WordPress** to a new host, server, or domain
*   **Cloning or duplicating** a site to reuse as a starting point or hand to a client
*   **Rolling back** a plugin, a theme or WordPress itself when an update breaks your site

= Looking for more? Revertix PRO =

**🚀 Revertix PRO** adds the tools agencies and power users need:

*   **Push / Pull sync** - Send changes between staging and live safely. Review every changed file and database row (down to a line-by-line diff) and apply only what you pick - or, on smaller plans, sync everything in one click. A full safety backup of live is always taken before a push.
*   **Scheduled automatic backups** - Set-and-forget backups on the schedule you choose (monthly, weekly, and more), with configurable retention.
*   **Off-site backups: Google Drive & FTP/SFTP** - Every backup is copied automatically to your own Google Drive (connect in one click) and/or your own FTP, FTPS or SFTP server, with its own retention. Optionally remove the local copy to save disk space, and restore straight from remote storage.
*   **Snapshots for premium/custom items** - Snapshot plugins and themes that are not on wordpress.org (no public version history) and roll back to any saved snapshot.
*   **Export download links, controlled** - The free plugin gives every export a private download link. PRO lets you set how long it lives, how many times it may be used, and a password to open it.
*   **Staging access control** - Decide which users and roles can reach your staging sites, and set a custom staging admin-bar colour.
*   **Priority support**.

**[Learn more about Revertix PRO](https://nitramix.com/projects/revertix/)**

== Installation ==

To install this plugin:

1. Install the plugin through the WordPress admin (Plugins > Add New > Upload), or upload the `revertix` folder to `/wp-content/plugins/` via FTP.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Open the new **Revertix** menu in your WordPress dashboard.

To create a staging site:

1. Go to **Revertix > Staging**.
2. Click **Create staging site** and wait for the job to finish (it runs in resumable chunks).
3. Use **Open** to visit it, **Reset** to re-clone it from live, or **Delete** to remove it.

To take a backup:

1. Go to **Revertix > Backups**.
2. Click **Create a backup**. Restore, download, or delete backups from the same screen.

To migrate your site:

1. Go to **Revertix > Export / Import**, create an export package, and download the `.zip`.
2. On the destination site, install Revertix and import that package under **Export / Import**.

== Frequently Asked Questions ==

= What does Revertix do? =
Revertix lets you create a staging copy of your WordPress site, take full backups (database and files), roll plugins and themes back to any earlier version, and export/import your whole site to move or duplicate it - all from your dashboard.

= What makes Revertix different from other backup and migration plugins? =
Three things. First, it is all-in-one: backup, staging, migration/cloning, and plugin/theme rollback live in a single plugin instead of four. Second, every heavy job runs in small, resumable, time-budgeted chunks, so big sites and slow or shared hosts do not hit PHP timeouts - and an interrupted job resumes where it stopped. Third, it is safe by default: a safety backup is taken automatically before any destructive action, and your most recent backup is always kept.

= Will it work on a large site or a slow host? =
Yes. Cloning, backup, export and import all run as resumable, time-budgeted jobs - a few seconds of work per step - so they keep going on large sites and shared hosts instead of failing on a PHP timeout.

= Is restoring a backup safe? =
Restoring overwrites the current site's database and files, so Revertix automatically takes a safety backup of the current site first. If anything looks wrong, you can restore that safety backup. Revertix also always keeps your most recent backup.

= What happens if something goes wrong with my site? =
Revertix is built to be careful: it takes an automatic safety backup before destructive actions and always keeps your most recent backup, so most changes are reversible. That said, backup, restore, import, export and sync operations modify and can overwrite your site's files and database, so you use the plugin at your own risk. You remain responsible for keeping your own independent, off-site backups, and we strongly recommend testing major operations (imports, restores, migrations) on a staging copy first. The plugin is provided "as is", without warranty of any kind, and the authors are not liable for any data loss, downtime, or damage - see the Disclaimer below.

= How safe is a share link? =
The address contains 43 random characters, so it cannot be guessed, and it is never published anywhere - the download is served by WordPress, not by a file sitting at a readable path. It expires after seven days, you can withdraw it at any moment, and it dies when you delete the package. What is stored on your site is encrypted using your site's own security keys, so a copy of your database does not hand anyone a working link. Treat it like a password: whoever holds it can download that package.

= Is the staging site public? =
No. Staging copies are login-protected, and a badge in the admin bar makes it obvious you are on staging and not on live.

= Does Revertix phone home or send my data anywhere? =
No. The free plugin does not send your content to any external service. Version history for the Rollback feature is fetched from the official WordPress.org API, the same source WordPress itself uses.

= How do I migrate my site to another host? =
Create an export package under Export / Import and download the `.zip`. Install Revertix on the destination site and import that package. The whole database and file set are moved for you.

= How do I clone or duplicate a WordPress site? =
Use Export / Import to package the whole site, then import it on the destination - that gives you an exact clone or duplicate of your WordPress site on another install, host, or domain. If you just want a copy to experiment on, create a staging site instead: it clones your live site into a private, login-protected environment in one click.

= How do I move WordPress from localhost to a live server (or live to localhost)? =
Create an export package on the source site, download the `.zip`, then install Revertix on the destination and import it. Revertix moves the whole database and file set and automatically rewrites the site URL and file paths for the new location, so a local site goes live (or a live site comes down to localhost) without manual SQL edits.

= Can I migrate to a new domain or change my site URL? =
Yes. When you import a package (or clone to staging) onto a site with a different address, Revertix runs a serialize-safe search and replace across every database table, swapping the old URL and path for the new one. Because the replacement is serialize-aware, it updates the URLs stored inside serialized options, widgets, and settings without breaking them.

= Will WooCommerce, Elementor, and other serialized data survive a migration? =
Yes. Revertix uses a serialize-safe search-and-replace, so serialized and JSON-encoded values - the kind stored by WooCommerce, page builders like Elementor and Divi, and many theme/plugin settings - keep their correct string lengths and stay valid after the URL is changed.

= Is Revertix a free backup plugin? =
Yes. Revertix is a free WordPress backup, staging and migration plugin. You can take full backups, create staging sites, roll back plugins and themes, and export/import your site at no cost. The separate Revertix PRO add-on provides scheduled automatic backups, off-site backups to Google Drive or FTP/SFTP, push/pull sync, snapshots for premium/custom items, and staging access control.

= Can I roll a plugin or theme back if an update breaks my site? =
Yes. Under **Revertix > Rollback**, you can roll a plugin or theme back to any earlier WordPress.org version, with a safety snapshot taken first. The separate Revertix PRO add-on adds snapshots for premium/custom items that are not on WordPress.org.

= Can I roll WordPress itself back to an older version? =
Yes. Open the **WordPress** panel at the top of **Revertix > Rollback**, pick a release and switch. It runs through WordPress' own updater, so maintenance mode, the file copy and the database step are handled by WordPress; your content, plugins, themes and uploads are not touched. Revertix only lists releases that support your PHP version and that Revertix itself runs on. Tick "Stay on this version" to pause automatic WordPress updates - otherwise WordPress would update itself again within hours - and resume them with one click when you are ready. Take a backup first: older releases may lack security fixes, and plugins that need a newer WordPress may stop working.

= Can I store backups off-site, for example in Google Drive? =
Not with the free plugin - it keeps backups on your own server and never sends them anywhere. The separate Revertix PRO add-on copies every backup automatically to your own Google Drive or your own FTP/FTPS/SFTP server.

= Is there a Pro version? =
Yes. The separate Revertix PRO add-on provides push/pull sync between staging and live, scheduled automatic backups, off-site backups to Google Drive or your own FTP/SFTP server, snapshots for premium/custom plugins & themes, staging access control, and priority support. See [nitramix.com](https://nitramix.com/).

= Does uninstalling remove my data? =
Only if you ask it to. Under **Revertix > Settings** there is a "Remove data on uninstall" option (off by default), so your settings and logs are kept unless you opt in.

== Screenshots ==

1. Staging - create, open, reset and delete staging copies
2. Backups - create, restore, download and delete backups
3. Export / Import - package your whole site and migrate it
4. Rollback - roll plugins and themes back to an earlier version
5. Settings - retention, staging access, notifications and uninstall

== Disclaimer ==

Revertix performs powerful operations - creating and restoring backups, importing and exporting entire sites, and rolling plugins, themes and WordPress itself back to earlier versions. These actions modify and can overwrite your site's files and database.

While Revertix is built to be safe - it takes an automatic safety backup before destructive actions, always keeps your most recent backup, and asks you to confirm before overwriting anything - you use it entirely at your own risk. To the fullest extent permitted by law, Nitramix Ltd. and the plugin authors are not liable for any data loss, downtime, corruption, or other damage arising from the use or inability to use the plugin.

Always keep your own independent, off-site backups, and test major operations (imports, restores, migrations) on a staging copy before running them on a live site. The Software is provided "AS IS", without warranty of any kind, as set out in the GNU General Public License under which it is distributed.

== Changelog ==

= 1.0.3 =
* **Added:** Rollback can now switch WordPress itself to an earlier release (or back up to a newer one), through WordPress' own updater. Automatic WordPress updates can be paused so the site stays on the version you chose.
* **Added:** previews of remote backup storage - Google Drive and your own FTP/SFTP server - provided by the separate Revertix PRO add-on. The free plugin itself never sends your backups anywhere.
* **Fixed:** on some hosts (cPanel) an import left wp-admin unreachable with a 403 error, because the new WordPress folders got stricter permissions than the rest of the site. Folders and files Revertix creates now follow the same permission rules as WordPress itself.
* **Improved:** System Info now shows the permissions of wp-admin and wp-includes and the folder/file permissions Revertix uses, to make permission problems quick to spot.
**Release date: November 13, 2026**

= 1.0.2 =
* **Fixed:** every `%` character in your content was mangled inside exported databases and backups. Percent-encoded values - any slug made from a non-Latin title, encoded URLs - grew to many times their length, and an import could fail outright. Please take a fresh export or backup: anything made before this version carries the damage.
* **Fixed:** on some hosts a new staging site quietly used the live database instead of its own copy, so changes made on "staging" were really made on the live site. If you have staging sites made before this version, delete and re-create them.
* **Fixed:** importing a package into a clean install could stop part-way behind a fatal error, and could leave nobody able to reach the admin. Imports now complete on a fresh site and restore it as it was exported - WordPress version included.
* **Fixed:** exporting a site with a large uploads folder could stop with no error and nothing in the log.
* **Added:** exports now include the WordPress files, so a package can rebuild a complete site. Whole-root *backups* remain a Revertix PRO feature.
* **Added:** a private download link for any export, to send to someone with no account on your site. It lasts seven days and can be withdrawn at any time.
* **Improved:** backups, packages and the log are stored under names nobody can guess, which protects them on servers that ignore `.htaccess`.
* **Fixed:** file counts and sizes printed a stray HTML code in languages that separate thousands with a space.
* Tested with WordPress 7.1
**Release date: August 21, 2026**

= 1.0.1 =
* **Fixed:** on some hosts a new staging site used the live database instead of its own copy. Everything looked correct in the panel, but changes made on staging were really being made on the live site. Staging sites created from now on are properly separated, and if Revertix cannot guarantee that separation it stops with a clear message instead of building the site.
* **Fixed:** a few ways a staging site could be set up incorrectly without saying so are now reported as errors.
* **Added:** on servers that do not read `.htaccess` files (nginx), Revertix now tells you that your backup folder is not protected and gives you the rule to add.
* **Improved:** every Revertix folder now carries its own protection files instead of relying on the parent folder.
* Tested with WordPress 7.0.3
**Release date: August 10, 2026**

= 1.0.0 =
* First release of Revertix - staging, backups, export/import migration, plugin & theme rollback, settings, and a system info & logs panel. All heavy operations run as resumable, time-budgeted jobs so large sites never hit a PHP timeout.
**Release date: July 24, 2026**

== Upgrade Notice ==

= 1.0.3 =
Roll WordPress itself back to an earlier release, and a fix for a 403 error on wp-admin after an import on some hosts (cPanel). Update Revertix PRO to 1.0.3 at the same time.

= 1.0.2 =
Important data fixes for exports, backups and staging. If you use staging, delete and re-create any site made before this version, and take a fresh backup.

= 1.0.1 =
Important fix for staging sites. If you use staging, please update, then delete and re-create any staging site you made before this version.

= 1.0.0 =
First release of Revertix.
