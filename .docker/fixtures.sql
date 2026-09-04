-- DinkyGallery test fixtures. Idempotent: safe to run repeatedly.
-- Loaded by setup.sh after Joomla install + plugin discovery.
SET @now = NOW();
SET NAMES utf8mb4;

-- Enable + configure the plugin (debug on, base_directory = images, rest = manifest defaults).
UPDATE jos_extensions
SET enabled = 1,
    params = '{"base_directory":"images","visible_cards":"3","card_aspect":"4\/3","card_min":"13rem","card_gap":"0","lightbox_size":"100","lightbox_color":"#000000","lightbox_padding":"10px","lightbox_loop":"1","middle_zone_action":"none","backdrop_opacity":"60","image_extensions":"jpg,jpeg,png,webp,gif,avif","sort_order":"asc","debug":"1"}'
WHERE element = 'dinkygallery' AND folder = 'content';

-- Article fixtures (catid 2 = "Uncategorised" on a default install).
INSERT IGNORE INTO jos_content
(id, asset_id, title, alias, introtext, `fulltext`, state, catid, created, created_by, created_by_alias, modified, modified_by, publish_up, images, urls, attribs, version, ordering, metakey, metadesc, access, hits, metadata, featured, language, note)
VALUES
(101, 0, 'DG Bare Form', 'dg-bare',
 '<p>Bare shortcode, twelve images, default parameters.</p><p>{gallery gallery-12}</p>',
 '', 1, 2, @now, 731, '', @now, 0, @now,
 '{}', '{}', '{}', 1, 1, '', 'Bare {gallery folder} form.', 1, 0, '{}', 1, '*', ''),

(102, 0, 'DG Attribute Form', 'dg-attributes',
 '<p>Attribute form: two cards, 80% lightbox.</p><p>{gallery folder="gallery-02" cards="2" size="80"}</p>',
 '', 1, 2, @now, 731, '', @now, 0, @now,
 '{}', '{}', '{}', 1, 2, '', 'Attribute {gallery folder="..." cards="..."} form.', 1, 0, '{}', 1, '*', ''),

(103, 0, 'DG Two Galleries', 'dg-two',
 '<p>First gallery, one image:</p><p>{gallery gallery-01}</p><p>Second gallery, small centered lightbox, middle closes, no wrap:</p><p>{gallery folder="gallery-12" size="40" middle="close" loop="0"}</p>',
 '', 1, 2, @now, 731, '', @now, 0, @now,
 '{}', '{}', '{}', 1, 3, '', 'Two independent galleries in one article.', 1, 0, '{}', 1, '*', ''),

(104, 0, 'DG Bad Folders', 'dg-badfolders',
 '<p>Missing folder (renders nothing + debug comment):</p><p>{gallery missing-folder}</p><p>Path traversal (rejected):</p><p>{gallery ../escape}</p><p>End.</p>',
 '', 1, 2, @now, 731, '', @now, 0, @now,
 '{}', '{}', '{}', 1, 4, '', 'Missing folder and ../escape must both render nothing.', 1, 0, '{}', 1, '*', ''),

(105, 0, 'DG Code Block', 'dg-codeblock',
 '<p>The next shortcode is inside a preformatted block and must stay literal:</p><pre>{gallery gallery-12}</pre><p>This one is real and must render:</p><p>{gallery gallery-02}</p>',
 '', 1, 2, @now, 731, '', @now, 0, @now,
 '{}', '{}', '{}', 1, 5, '', '{gallery} inside <pre> stays raw; the one outside renders.', 1, 0, '{}', 1, '*', ''),

(110, 0, 'DinkyGallery Test Page', 'dinkygallery-test',
 '<p>Manuelle Teststrecke fuer das DinkyGallery-Plugin. Jede Ueberschrift beschreibt, was der folgende Kurzcode zeigt.</p>\n<h2>1. Standard (visible_cards, Lightbox 100%)</h2>\n<p>{gallery gallery-12}</p>\n<h2>2. Fuenf Karten (cards="5")</h2>\n<p>{gallery folder="gallery-05" cards="5"}</p>\n<h2>3. Kleine zentrierte Lightbox, Mitte schliesst (size="50" middle="close")</h2>\n<p>{gallery folder="gallery-12" size="50" middle="close"}</p>\n<h2>4. Kein Umlauf (loop="0")</h2>\n<p>{gallery folder="gallery-05" loop="0"}</p>\n<h2>5. Einzelbild</h2>\n<p>{gallery gallery-01}</p>\n<h2>6. Hochformat-Serie</h2>\n<p>{gallery gallery-portrait}</p>\n<h2>7. Absteigend sortiert (sort="desc")</h2>\n<p>{gallery folder="gallery-12" sort="desc"}</p>\n<h2>8. Zwei Galerien im selben Absatz</h2>\n<p>{gallery gallery-02} und direkt danach {gallery gallery-01}</p>\n<h2>9. Kartenabstand (gap="16px")</h2>\n<p>{gallery folder="gallery-12" gap="16px"}</p>\n<h2>10. sigplus-Form: Ordner im Schliesstag</h2>\n<p>{gallery}gallery-02{/gallery}</p>\n<h2>11. sigplus-Form: Legacy-Attribute werden ignoriert</h2>\n<p>{gallery width=240 height=240 alignment=left deftitle="Demo" lightbox=fancybox}gallery-05{/gallery}</p>\n<h2>12. sigplus-Form: fuehrender Slash</h2>\n<p>{gallery}/gallery-01{/gallery}</p>\n<h2>13. sigplus-Form: Einzeldatei</h2>\n<p>{gallery}gallery-12/05-huge.jpg{/gallery}</p>\n<h2>14. aspect="16/9" (Override)</h2>\n<p>{gallery folder="gallery-12" aspect="16/9"}</p>\n<h2>15. aspect="1:1"</h2>\n<p>{gallery folder="gallery-05" aspect="1:1"}</p>',
 '', 1, 2, @now, 731, '', @now, 0, @now,
 '{}', '{}', '{}', 1, 10, '', 'Manual test surface: labelled {gallery} cases incl. sigplus closing-tag form.', 1, 0, '{}', 1, '*', '');

-- Front-end menu item pointing at the test page (sibling of Home under the menu root).
INSERT IGNORE INTO jos_menu
(id, menutype, title, alias, note, path, link, type, published, parent_id, level, component_id,
 checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id, publish_up, publish_down)
VALUES
(110, 'mainmenu', 'DinkyGallery Test', 'dinkygallery-test', '', 'dinkygallery-test',
 'index.php?option=com_content&view=article&id=110', 'component', 1, 1, 1, 19,
 NULL, NULL, 0, 1, '', 0, '{}', 43, 44, 0, '*', 0, NULL, NULL);

-- Keep the menu root's right bound consistent (self-correcting, safe to re-run).
UPDATE jos_menu
SET rgt = (SELECT t.mx FROM (SELECT MAX(rgt) AS mx FROM jos_menu WHERE id <> 1) t) + 1
WHERE id = 1;

-- Custom HTML module carrying a {gallery} with "Prepare Content" on: exercises the
-- mod_custom.content context. Shown in the right sidebar on every page.
INSERT IGNORE INTO jos_modules
(id, asset_id, title, note, content, ordering, position, checked_out, checked_out_time,
 publish_up, publish_down, published, module, access, showtitle, params, client_id, language)
VALUES
(900, 0, 'DG Module Test', '', '<p>Modul: {gallery gallery-02}</p>', 1, 'sidebar-right',
 NULL, NULL, NULL, NULL, 1, 'mod_custom', 1, 1,
 '{"prepare_content":"1","layout":"_:default","moduleclass_sfx":"","module_tag":"div","header_tag":"h3","style":"0"}',
 0, '*');
INSERT IGNORE INTO jos_modules_menu (moduleid, menuid) VALUES (900, 0);
