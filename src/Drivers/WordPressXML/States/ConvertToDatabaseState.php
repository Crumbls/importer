<?php

namespace Crumbls\Importer\Drivers\WordPressXML\States;

use Crumbls\Importer\States\AbstractState;
use PDO;

class ConvertToDatabaseState extends AbstractState
{
	private PDO $db;
	private const BATCH_SIZE = 1000;

	public function getName(): string
	{
		return 'convert-to-database';
	}

	public function handle(): void
	{
		$record = $this->getRecord();
		$md = $record->metadata ?? [];

		$dbPath = array_key_exists('db_path', $md) ? $md['db_path'] : null;
		if (!$dbPath) {
			$dbPath = database_path('/wp_import_' . $record->getKey() . '.sqlite');
			$md['db_path'] = $dbPath;
			$record->metadata = $md;
			$record->update(['metadata' => $md]);
		}

		$this->db = new PDO('sqlite:' . $dbPath);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$this->createTables();
		$this->processXML($record->source);
	}

	private function processXML(string $source): void
	{
		$reader = new \XMLReader();
		$reader->open($source);

		$batch = [];
		while ($reader->read()) {
			if ($reader->nodeType === \XMLReader::ELEMENT) {
				if ($reader->name === 'item') {
					$item = $this->parseItem($reader->readOuterXml());
					$batch[] = $item;

					if (count($batch) >= self::BATCH_SIZE) {
						$this->insertBatch($batch);
						$batch = [];
					}
				} elseif ($reader->name === 'wp:author') {
					$this->insertUser($reader->readOuterXml());
				}
			}
		}

		if (!empty($batch)) {
			$this->insertBatch($batch);
		}

		$reader->close();
	}

	private function createTables(): void
	{
		$this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                ID INTEGER PRIMARY KEY,
                user_login TEXT NOT NULL,
                user_pass TEXT NOT NULL DEFAULT '',
                user_nicename TEXT NOT NULL DEFAULT '',
                user_email TEXT NOT NULL,
                user_url TEXT NOT NULL DEFAULT '',
                user_registered DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_activation_key TEXT NOT NULL DEFAULT '',
                user_status INTEGER NOT NULL DEFAULT 0,
                display_name TEXT NOT NULL DEFAULT '',
                spam INTEGER NOT NULL DEFAULT 0,
                deleted INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS usermeta (
                umeta_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                meta_key TEXT DEFAULT NULL,
                meta_value TEXT,
                FOREIGN KEY(user_id) REFERENCES users(ID)
            );

            CREATE TABLE IF NOT EXISTS posts (
        ID INTEGER PRIMARY KEY,
        post_author INTEGER NOT NULL DEFAULT 0,
        post_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        post_date_gmt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        post_content TEXT DEFAULT '',
        post_title TEXT DEFAULT '',
        post_excerpt TEXT DEFAULT '',
        post_status TEXT DEFAULT 'publish',
        comment_status TEXT DEFAULT 'open',
        ping_status TEXT DEFAULT 'open',
        post_password TEXT DEFAULT '',
        post_name TEXT DEFAULT '',
        to_ping TEXT DEFAULT '',
        pinged TEXT DEFAULT '',
        post_modified DATETIME DEFAULT CURRENT_TIMESTAMP,
        post_modified_gmt DATETIME DEFAULT CURRENT_TIMESTAMP,
        post_content_filtered TEXT DEFAULT '',
        post_parent INTEGER DEFAULT 0,
        guid TEXT DEFAULT '',
        menu_order INTEGER DEFAULT 0,
        post_type TEXT DEFAULT 'post',
        post_mime_type TEXT DEFAULT '',
        comment_count INTEGER DEFAULT 0
    );


            CREATE TABLE IF NOT EXISTS postmeta (
                meta_id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                meta_key TEXT DEFAULT NULL,
                meta_value TEXT,
                FOREIGN KEY(post_id) REFERENCES posts(ID)
            );

            CREATE TABLE IF NOT EXISTS comments (
                comment_ID INTEGER PRIMARY KEY,
                comment_post_ID INTEGER NOT NULL,
                comment_author TEXT NOT NULL,
                comment_author_email TEXT NOT NULL,
                comment_author_url TEXT NOT NULL,
                comment_author_IP TEXT NOT NULL,
                comment_date DATETIME NOT NULL,
                comment_date_gmt DATETIME NOT NULL,
                comment_content TEXT NOT NULL,
                comment_karma INTEGER NOT NULL,
                comment_approved TEXT NOT NULL,
                comment_agent TEXT NOT NULL,
                comment_type TEXT NOT NULL,
                comment_parent INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                FOREIGN KEY(comment_post_ID) REFERENCES posts(ID)
            );

            CREATE TABLE IF NOT EXISTS commentmeta (
                meta_id INTEGER PRIMARY KEY,
                comment_id INTEGER NOT NULL,
                meta_key TEXT DEFAULT NULL,
                meta_value TEXT,
                FOREIGN KEY(comment_id) REFERENCES comments(comment_ID)
            );

            CREATE TABLE IF NOT EXISTS terms (
                term_id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                term_group INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER NOT NULL,
                taxonomy TEXT NOT NULL,
                description TEXT NOT NULL,
                parent INTEGER NOT NULL,
                count INTEGER NOT NULL,
                FOREIGN KEY(term_id) REFERENCES terms(term_id)
            );

            CREATE TABLE IF NOT EXISTS term_relationships (
                object_id INTEGER NOT NULL,
                term_taxonomy_id INTEGER NOT NULL,
                term_order INTEGER NOT NULL,
                PRIMARY KEY (object_id, term_taxonomy_id),
                FOREIGN KEY(object_id) REFERENCES posts(ID),
                FOREIGN KEY(term_taxonomy_id) REFERENCES term_taxonomy(term_taxonomy_id)
            );

            CREATE TABLE IF NOT EXISTS options (
                option_id INTEGER PRIMARY KEY,
                option_name TEXT NOT NULL UNIQUE,
                option_value TEXT NOT NULL,
                autoload TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS media (
                ID INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL,
                original_url TEXT NOT NULL,
                file_path TEXT,
                file_name TEXT NOT NULL,
                attachment_url TEXT,
                guid TEXT NOT NULL,
                post_mime_type TEXT NOT NULL,
                FOREIGN KEY(post_id) REFERENCES posts(ID)
            );

            CREATE TABLE IF NOT EXISTS links (
                link_id INTEGER PRIMARY KEY,
                link_url TEXT NOT NULL,
                link_name TEXT NOT NULL,
                link_image TEXT NOT NULL,
                link_target TEXT NOT NULL,
                link_description TEXT NOT NULL,
                link_visible TEXT NOT NULL,
                link_owner INTEGER NOT NULL,
                link_rating INTEGER NOT NULL,
                link_updated DATETIME NOT NULL,
                link_rel TEXT NOT NULL,
                link_notes TEXT NOT NULL,
                link_rss TEXT NOT NULL
            );
        ");
	}

	private function parseItem(string $xml): array
	{
		$doc = new \DOMDocument();
		$doc->loadXML($xml);
		$xpath = new \DOMXPath($doc);

		$xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');
		$xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
		$xpath->registerNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');
		$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');

		$post = [
			'post' => [
				'post_author' => $this->getNodeValue($xpath, './/dc:creator'),
				'post_date' => $this->getNodeValue($xpath, './/wp:post_date'),
				'post_date_gmt' => $this->getNodeValue($xpath, './/wp:post_date_gmt'),
				'post_content' => $this->getNodeValue($xpath, './/content:encoded'),
				'post_title' => $this->getNodeValue($xpath, './/title'),
				'post_excerpt' => $this->getNodeValue($xpath, './/excerpt:encoded'),
				'post_status' => $this->getNodeValue($xpath, './/wp:status'),
				'comment_status' => $this->getNodeValue($xpath, './/wp:comment_status'),
				'ping_status' => $this->getNodeValue($xpath, './/wp:ping_status'),
				'post_password' => $this->getNodeValue($xpath, './/wp:post_password'),
				'post_name' => $this->getNodeValue($xpath, './/wp:post_name'),
				'to_ping' => $this->getNodeValue($xpath, './/wp:to_ping'),
				'pinged' => $this->getNodeValue($xpath, './/wp:pinged'),
				'post_modified' => $this->getNodeValue($xpath, './/wp:post_modified'),
				'post_modified_gmt' => $this->getNodeValue($xpath, './/wp:post_modified_gmt'),
				'post_content_filtered' => '',
				'post_parent' => $this->getNodeValue($xpath, './/wp:post_parent'),
				'guid' => $this->getNodeValue($xpath, './/guid'),
				'menu_order' => (int)$this->getNodeValue($xpath, './/wp:menu_order'),
				'post_type' => $this->getNodeValue($xpath, './/wp:post_type'),
				'post_mime_type' => $this->getNodeValue($xpath, './/wp:post_mime_type'),
				'comment_count' => 0
			],
			'meta' => $this->parsePostMeta($xpath),
			'terms' => $this->parseTerms($xpath),
			'comments' => $this->parseComments($xpath)
		];

		// Handle attachments
		if ($post['post']['post_type'] === 'attachment') {
			$post['media'] = [
				'original_url' => $this->getNodeValue($xpath, './/wp:attachment_url'),
				'file_name' => basename($this->getNodeValue($xpath, './/wp:attachment_url')),
				'attachment_url' => $this->getNodeValue($xpath, './/wp:attachment_url'),
				'guid' => $this->getNodeValue($xpath, './/guid'),
				'post_mime_type' => $post['post']['post_mime_type']
			];
		}

		return $post;
	}

	private function parsePostMeta(\DOMXPath $xpath): array
	{
		$metas = [];
		$metaNodes = $xpath->query('.//wp:postmeta');
		foreach ($metaNodes as $node) {
			$metas[] = [
				'meta_key' => $this->getNodeValue($xpath, './/wp:meta_key', $node),
				'meta_value' => $this->getNodeValue($xpath, './/wp:meta_value', $node)
			];
		}
		return $metas;
	}

	private function parseTerms(\DOMXPath $xpath): array
	{
		$terms = [];
		$categories = $xpath->query('.//category');
		foreach ($categories as $category) {
			$terms[] = [
				'name' => $category->nodeValue,
				'slug' => $category->getAttribute('nicename'),
				'taxonomy' => $category->getAttribute('domain')
			];
		}
		return $terms;
	}

	private function parseComments(\DOMXPath $xpath): array
	{
		$comments = [];
		$commentNodes = $xpath->query('.//wp:comment');
		foreach ($commentNodes as $node) {
			$comments[] = [
				'comment_author' => $this->getNodeValue($xpath, './/wp:comment_author', $node),
				'comment_author_email' => $this->getNodeValue($xpath, './/wp:comment_author_email', $node),
				'comment_author_url' => $this->getNodeValue($xpath, './/wp:comment_author_url', $node),
				'comment_author_IP' => $this->getNodeValue($xpath, './/wp:comment_author_IP', $node),
				'comment_date' => $this->getNodeValue($xpath, './/wp:comment_date', $node),
				'comment_date_gmt' => $this->getNodeValue($xpath, './/wp:comment_date_gmt', $node),
				'comment_content' => $this->getNodeValue($xpath, './/wp:comment_content', $node),
				'comment_approved' => $this->getNodeValue($xpath, './/wp:comment_approved', $node),
				'comment_type' => '',
				'comment_parent' => (int)$this->getNodeValue($xpath, './/wp:comment_parent', $node),
				'user_id' => (int)$this->getNodeValue($xpath, './/wp:comment_user_id', $node),
				'comment_karma' => 0
			];
		}
		return $comments;
	}

	private function insertUser(string $xml): void
	{
		$doc = new \DOMDocument();
		$doc->loadXML($xml);
		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('wp', 'http://wordpress.org/export/1.2/');

		$stmt = $this->db->prepare("
            INSERT INTO users (
                user_login, user_email, display_name, user_nicename,
                user_url, user_registered, user_status
            ) VALUES (
                :login, :email, :display_name, :nicename,
                :url, CURRENT_TIMESTAMP, 0
            )
        ");

		$login = $this->getNodeValue($xpath, './/wp:author_login');
		$stmt->execute([
			'login' => $login,
			'email' => $this->getNodeValue($xpath, './/wp:author_email'),
			'display_name' => $this->getNodeValue($xpath, './/wp:author_display_name'),
			'nicename' => strtolower($login),
			'url' => $this->getNodeValue($xpath, './/wp:author_url') ?? ''
		]);

		$userId = $this->db->lastInsertId();

		// Insert author meta
		$metaStmt = $this->db->prepare("
            INSERT INTO usermeta (user_id, meta_key, meta_value)
            VALUES (:user_id, :meta_key, :meta_value)
        ");

		$meta = [
			'first_name' => $this->getNodeValue($xpath, './/wp:author_first_name'),
			'last_name' => $this->getNodeValue($xpath, './/wp:author_last_name'),
			'nickname' => $login
		];

		foreach ($meta as $key => $value) {
			if ($value) {
				$metaStmt->execute([
					'user_id' => $userId,
					'meta_key' => $key,
					'meta_value' => $value
				]);
			}
		}
	}

	private function insertBatch(array $items): void
	{
		$this->db->beginTransaction();

		try {
			foreach ($items as $item) {
				// Insert post
				$postStmt = $this->db->prepare("
                INSERT INTO posts (
                    post_author, post_date, post_date_gmt, post_content,
                    post_title, post_excerpt, post_status, comment_status,
                    ping_status, post_password, post_name, to_ping,
                    pinged, post_modified, post_modified_gmt, post_content_filtered,
                    post_parent, guid, menu_order, post_type,
                    post_mime_type, comment_count
                ) VALUES (
                    :post_author, :post_date, :post_date_gmt, :post_content,
                    :post_title, :post_excerpt, :post_status, :comment_status,
                    :ping_status, :post_password, :post_name, :to_ping,
                    :pinged, :post_modified, :post_modified_gmt, :post_content_filtered,
                    :post_parent, :guid, :menu_order, :post_type,
                    :post_mime_type, :comment_count
                )
            ");

				$postStmt->execute($item['post']);
				$postId = $this->db->lastInsertId();

				// Insert post meta
				$metaStmt = $this->db->prepare("
                INSERT INTO postmeta (post_id, meta_key, meta_value)
                VALUES (:post_id, :meta_key, :meta_value)
            ");

				foreach ($item['meta'] as $meta) {
					$meta['post_id'] = $postId;
					$metaStmt->execute($meta);
				}

				// Insert terms
				$termStmt = $this->db->prepare("
                INSERT OR IGNORE INTO terms (name, slug, term_group)
                VALUES (:name, :slug, 0)
            ");

				$taxonomyStmt = $this->db->prepare("
                INSERT OR IGNORE INTO term_taxonomy (
                    term_id, taxonomy, description, parent, count
                ) VALUES (
                    :term_id, :taxonomy, '', 0, 0
                )
            ");

				$relationshipStmt = $this->db->prepare("
                INSERT INTO term_relationships (
                    object_id, term_taxonomy_id, term_order
                ) VALUES (
                    :object_id, :term_taxonomy_id, 0
                )
            ");

				foreach ($item['terms'] as $term) {
					$termStmt->execute([
						'name' => $term['name'],
						'slug' => $term['slug']
					]);
					$termId = $this->db->lastInsertId();

					$taxonomyStmt->execute([
						'term_id' => $termId,
						'taxonomy' => $term['taxonomy']
					]);
					$taxonomyId = $this->db->lastInsertId();

					$relationshipStmt->execute([
						'object_id' => $postId,
						'term_taxonomy_id' => $taxonomyId
					]);
				}

				// Insert comments
				$commentStmt = $this->db->prepare("
                INSERT INTO comments (
                    comment_post_ID, comment_author, comment_author_email,
                    comment_author_url, comment_author_IP, comment_date,
                    comment_date_gmt, comment_content, comment_karma,
                    comment_approved, comment_agent, comment_type,
                    comment_parent, user_id
                ) VALUES (
                    :comment_post_ID, :comment_author, :comment_author_email,
                    :comment_author_url, :comment_author_IP, :comment_date,
                    :comment_date_gmt, :comment_content, :comment_karma,
                    :comment_approved, '', :comment_type,
                    :comment_parent, :user_id
                )
            ");

				foreach ($item['comments'] as $comment) {
					$comment['comment_post_ID'] = $postId;
					$commentStmt->execute($comment);
				}

				// Insert media if present
				if (isset($item['media'])) {
					$mediaStmt = $this->db->prepare("
                    INSERT INTO media (
                        post_id, original_url, file_name,
                        attachment_url, guid, post_mime_type
                    ) VALUES (
                        :post_id, :original_url, :file_name,
                        :attachment_url, :guid, :post_mime_type
                    )
                ");

					$item['media']['post_id'] = $postId;
					$mediaStmt->execute($item['media']);
				}
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function getNodeValue(\DOMXPath $xpath, string $query, \DOMNode $contextNode = null): ?string
	{
		$nodes = $xpath->query($query, $contextNode);
		return $nodes->length > 0 ? $nodes->item(0)->nodeValue : null;
	}
}