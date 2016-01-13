<?php
/**
 * Ameblo2WP class
 * 
 * @copyright		Copyright 2016 - , COWBELL Corportaion and tecking
 * @link			https://github.com/tecking
 * @license			https://opensource.org/licenses/GPL-2.0
 */


class Ameblo2WP {

	/**
	 * コンストラクタ 
	 * PHP Simple HTML DOM Parser を読み込む
	 * http://simplehtmldom.sourceforge.net/
	 */
	function __construct() {
		require_once( dirname(__FILE__) . '/../inc/simple_html_dom.php' );
	}

	/**
	 * 記事アーカイブの index ページ URL を取得
	 * @param string $blogUrl ブログ URL
	 * @return string 記事アーカイブの index ページ URL
	 */
		public function getArchiveIndexUrl($blogUrl) {
			if ( !preg_match('/ameblo\.jp/', $blogUrl ) ) {
				return false;
			}
			if ( !preg_match('/^.*\/$/', $blogUrl ) ) {
				$blogUrl .= '/';
			}
			return $archiveIndexUrl = $blogUrl . 'entrylist.html';
		}

	/**
	 * 記事アーカイブページの全 URL を取得 
	 * @param srting $archiveIndexUrl 記事アーカイブの index ページ URL
	 * @return array 記事アーカイブページの全 URL
	 */
		public function getArchivesUrl($archiveIndexUrl) {
			$archivesUrl = [];
			$archivesUrl[] = $archiveIndexUrl;
			$next = '';
			$page = 2; // 記事アーカイブページの連番が 2 から始まるため
			$i = 0;

			while ( $page != 0 ) {
				$next = preg_replace('/(entrylist)(-)?([\d]*)(\.html)/', '${1}-' . $page . '${4}', $archivesUrl[$i]);
				$html = file_get_html( $next );
				if ( $html -> find('a.pagingNext') ) {
					$archivesUrl[] = $next;
					$page++;
				}
				else {
					$archivesUrl[] = $next;
					$page = 0;
				}
				$i++;
				$html->clear();
			}	
			return $archivesUrl;
		}

	/**
	 * 全記事の URL を取得 
	 * @param array $archivesUrl 記事アーカイブページの全 URL
	 * @return array 全記事の URL
	 */
		public function getPostsUrl($archivesUrl) {
			$postsUrl = [];
			$i = 0;
			foreach ($archivesUrl as $key => $value) {
				$html = file_get_html($value);
				foreach ( $html -> find('a.contentTitle') as $key => $element ) {
					$postsUrl[$i] = $element -> href;
					$i++;
				}
				$html->clear();
			}
			return $postsUrl;
		}

	/**
 	 * 全記事の記事データを取得
 	 * @param array $postsUrl 全記事の URL
 	 * @return array 全記事の記事データ
 	 */
		public function getPostsData($postsUrl) {
			$postsData = [];
			foreach ($postsUrl as $key => $value) {
				$html = file_get_html($value);

				// 記事タイトル
				$postsData{$key}['title']        = $html -> find('article h1 a', 0) -> plaintext;
				// 記事本文
				$postsData{$key}['content']      = $html -> find('.articleText', 0) -> innertext;
				// 記事テーマ（＝カテゴリー）
				$postsData{$key}['category']     = $html -> find('.articleTheme a', 0) -> innertext;
				// 記事投稿日時
				// ユーザー設定により Y-m-d H:i:s 以外の表示もできるため datetime 属性値（ Y-m-d ）を取得している
				$postsData{$key}['date']         = $html -> find('.articleTime time', 0) -> datetime;
				// 移設前の記事 URL
				$postsData{$key}['original_url'] = $value;

			}
			$html->clear();
			return $postsData;
		}

	/**
	 * WordPress へ記事データをインポート
	 * 移設前の記事 URL はカスタムフィールド original_url に格納する
	 * @param array $postData 単一記事の記事データ
	 * @return string インポート後の記事 ID
	 */
		public function createPost($postData) {
			$this->putTempFile($postData['content']);
			exec('wp post create ' . dirname(__FILE__) . '/../tmp/tmp.txt --post_title="' . $postData['title'] . '" --post_type=post --post_status=publish --post_date="' . $postData['date'] .'" --porcelain', $postId );
			exec('wp post term set ' . $postId[0] . ' category ' . $postData['category']);
			exec('wp post meta set ' . $postId[0] . ' original_url "' . $postData['original_url'] . '"');
			return $postId[0];
		}

	/**
 	 * 記事本文から画像ファイルの全 URL を取得
 	 * @param string $postContent 単一記事の本文
 	 * @return array 単一記事内に含まれる画像ファイルの全 URL
 	 */
		public function getImagesUrl($postContent) {
			$imagesUrl = [];
			preg_match_all('/<a.+?class="detailOn".+?>.+?<\/a>/', $postContent, $matches);
			foreach ($matches[0] as $key => $value) {
				preg_match('/"(http:\/\/ameblo\.jp.+?)"/', $value, $href);
				preg_match('/"(http:\/\/stat\.ameba\.jp.+?)"/', $value, $src);
				$imagesUrl{$key}['href'] = $href[1];
				$imagesUrl{$key}['src'] = $src[1];
			}
			return $imagesUrl;
		}

	/**
	 * 画像ファイルを WordPress へインポート
	 * @param string $postId 画像とひも付ける単一記事の ID
	 * @param array 単一記事内に含まれる画像の全 URL
	 * @return void
	 */
		public function importImage($postId, $imagesUrl) {
			foreach ( $imagesUrl as $key => $value ) {
				exec('wp media import ' . $value['src'] . ' --post_id=' . $postId . ' --title= --alt= --desc=');
			}
		}

	/**
	 * コメントアーカイブページの全 URL を取得
	 * @param string $postUrl 単一記事の URL 
	 * @return array 単一記事にひも付くコメントアーカイブページの全 URL
	 */
		public function getCommentsUrl($postUrl) {
			$commentsUrl = [];
			$commentsUrl[] = $postUrl;
			$next = '';
			$page = 2; // コメントアーカイブページの連番が 2 から始まるため
			$i = 0;

			while ( $page != 0 ) {
				$next = preg_replace('/(entry)([\d]*)(\-.+?\.html)/', '${1}' . $page . '${3}', $commentsUrl[$i]);
				$html = file_get_html( $next );
				if ( $html -> find('a.textPagingNext') ) {
					$commentsUrl[] = $next;
					$page++;
				}
				else {
					if ( $html -> find('span.textPagingNext') ) {
						$commentsUrl[] = $next;
					}
					$page = 0;
				}
				$i++;
				$html->clear();
			}
			return $commentsUrl;
		}

	/**
 	 * 単一記事にひも付く全コメントデータを取得
 	 * @param array $commentsUrl 単一記事にひも付くコメントアーカイブページの全 URL
 	 * @return array 単一記事にひも付く全コメントデータ
 	 */
		public function getCommentsData($commentsUrl) {
			$commentsData = [];
			$i = 0;
			foreach ( $commentsUrl as $value) {
				$html = file_get_html($value);
				foreach ( $html -> find('.blogComment') as $key => $element ) {

					// コメントタイトル
					$commentsData{$i}['title']      = $element -> find('.commentHeader', 0) -> plaintext;
					// コメント本文
					$commentsData{$i}['content']    = $element -> find('.commentBody', 0) -> innertext;
					// コメント投稿者
					$commentsData{$i}['author']     = $element -> find('.commentAuthor', 0) -> plaintext;
					// コメント投稿者の URL
					$commentsData{$i}['author_url'] = $element -> find('.commentAuthor', 0) -> href;
					// コメント投稿日時
					$commentsData{$i}['date']       = $element -> find('.commentTime time', 0) -> plaintext;

					$i++;
				}
				$html->clear();
			}
			return $commentsData;
		}

	/**
	 * コメントを WordPress にインポート
	 * コメント本文・投稿者名にバッククォートが含まれている場合 exec でエラーとなるので実体参照に置換している
	 * @param string $postId コメントとひも付ける単一記事の ID
	 * @param array $commentsData 単一記事にひも付く全コメントデータ
	 * @return void
	 */
		public function createComments($postId, $commentsData) {
			foreach ($commentsData as $key => $value) {
				$this->putTempFile(str_replace('`', '&#096;', $value['title'] . '<br />' . $value['content']));
				exec('wp comment create --comment_post_ID=' . $postId . ' --comment_content="' . rtrim($this->getTempFile()) . '" --comment_author="' . str_replace('`', '&#096;', $value['author']) . '" --comment_author_url="' . $value['author_url'] . '" --comment_date="' . $value['date'] . '"');
			}
		}

	/**
	 * 一時ファイルの書き込み
	 * @param string $body ファイルに書き込む文字列
	 * @return void
	 */
		public function putTempFile($body) {
			file_put_contents( dirname(__FILE__) . '/../tmp/tmp.txt', $body, LOCK_EX );
		}

	/**
	 * 一時ファイルの読み取り
	 * @return string
	 */
		public function getTempFile() {
			$body = file_get_contents( dirname(__FILE__) . '/../tmp/tmp.txt' );
			return $body;
		}

	/**
	 * WordPress にインポートした記事にある画像ファイル URL の置換
	 * 移設前の画像ファイル URL を guid の値に置換する
	 * @param string $postId 単一記事の ID
	 * @param array $imagesUrl 単一記事内に含まれる画像ファイルの全 URL
	 * @return void
	 */
		public function searchReplace($postId, $imagesUrl) {
			exec('wp post list --fields=ID,guid --post_parent=' . $postId . ' --post_type=attachment --format=json', $json);
			foreach (array_reverse(json_decode($json[0])) as $key => $value) {
				exec('wp search-replace ' . $imagesUrl{$key}['href'] . ' ' . $value->guid);
				exec('wp search-replace ' . $imagesUrl{$key}['src'] . ' ' . $value->guid);
			}
		}
}
