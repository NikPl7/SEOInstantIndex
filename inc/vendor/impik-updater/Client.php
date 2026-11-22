<?php
/**
 * Impik Updater — обновления плагинов impik.dev из wp-admin, только вручную.
 *
 * Один исходник на все продукты. В плагин попадает копией через `node vendor.mjs <плагин> <Пространство\Имён>`:
 * скрипт меняет строку namespace, поэтому разные версии модуля в разных плагинах друг другу не мешают.
 * Править копию в плагине нельзя — только здесь и заново скопировать.
 *
 * Сервер отдаёт один подписанный манифест на все продукты: https://updates.impik.dev/manifest.json
 *   { "v": 1, "key": "k1", "payload": "<base64 JSON>", "sig": "<base64 ed25519>" }
 * payload: { "issued": 1730800000, "products": { "<slug>": {
 *     "stable": { "version", "package", "sha256", "requires", "requires_php", "tested", "frame_contracts": [1], "changelog" },
 *     "beta":   { … }, "blocked": [ "0.3.1" ] } } }
 *
 * Правила (архитектура Impik, раздел 2):
 * - подпись не сошлась — обновлений нет вообще, без запасного пути;
 * - запрос один на все продукты (общий кэш сайта), таймаут 3 с, при сбое пауза 1 → 6 → 24 ч;
 * - с публичных страниц сайта сервер не спрашиваем никогда;
 * - несовместимое обновление не предлагается, причина пишется в строке плагина;
 * - отозванные версии (blocked в манифесте и откаты Impik Guard) не предлагаются;
 * - автообновлений нет.
 *
 * PHP 7.4+, WordPress 5.8+ (подпись — sodium; в WordPress есть запасная sodium_compat).
 *
 * Копия для seo-instantindex — не править, обновлять через impik-updater/vendor.mjs.
 *
 * @package ImpikUpdater
 */

namespace Impik\SeoInstantIndex\Updater;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Client' ) ) {

	final class Client {

		const MODULE    = '1.0.0';
		const HOST      = 'updates.impik.dev';
		const MANIFEST  = 'https://updates.impik.dev/manifest.json';
		/** Открытые ключи подписи манифеста, base64. Закрытый — только на impik-server. */
		const KEYS      = array( 'k1' => 'r3r3JR5smWLxdo3KPvz0FrSnP9dihYkUN++Uvvhqn4A=' );
		const TIMEOUT   = 3;
		const TTL       = 12 * 3600;
		const BACKOFF   = array( 3600, 6 * 3600, 24 * 3600 );
		/** Общие для всех копий модуля ключи — формат не менять без смены «v». */
		const T_RAW     = 'impik_updates_manifest';
		const O_FAIL    = 'impik_updates_backoff';
		const O_ISSUED  = 'impik_updates_issued';
		const O_CHANNEL = 'impik_updates_channel';
		const O_BLOCKED = 'impik_guard_blocked';

		private $file;
		private $slug;
		private $version;
		private $basename;
		/** Разобранный манифест на время запроса: false — ещё не читали. */
		private static $payload = false;

		/**
		 * @param string $file    __FILE__ главного файла плагина.
		 * @param string $slug    папка плагина = имя продукта в манифесте.
		 * @param string $version текущая версия.
		 */
		public static function register( $file, $slug, $version ) {
			static $done = array();
			if ( isset( $done[ $slug ] ) ) {
				return;
			}
			$done[ $slug ] = true;
			new self( $file, $slug, $version );
		}

		private function __construct( $file, $slug, $version ) {
			$this->file     = $file;
			$this->slug     = $slug;
			$this->version  = $version;
			$this->basename = plugin_basename( $file );

			// WordPress зовёт update_plugins_<хост из заголовка Update URI> — заголовок в плагине обязателен.
			add_filter( 'update_plugins_' . self::HOST, array( $this, 'check' ), 10, 3 );
			add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
			add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 4 );
			add_action( 'in_plugin_update_message-' . $this->basename, array( $this, 'row_message' ), 10, 2 );
			// Решение владельца: никаких автообновлений. Impik Guard запрещает их на всём сайте, здесь — дубль на случай без Guard.
			add_filter( 'auto_update_plugin', array( $this, 'no_auto_update' ), PHP_INT_MAX, 2 );
			add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_html' ), 10, 2 );
		}

		public function no_auto_update( $update, $item ) {
			return ( is_object( $item ) && isset( $item->plugin ) && $item->plugin === $this->basename ) ? false : $update;
		}

		public function auto_update_html( $html, $plugin_file ) {
			return $plugin_file === $this->basename ? '<span class="impik-no-autoupdate">Только вручную</span>' : $html;
		}

		// ── манифест ────────────────────────────────────────────────────────────

		/** Можно ли сейчас ходить на сервер: только админка, cron и wp-cli. */
		private static function may_fetch() {
			return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		}

		/** Проверенный манифест или null. $force — «Проверить снова» на экране «Обновления». */
		private static function payload( $force = false ) {
			if ( ! $force && false !== self::$payload ) {
				return self::$payload;
			}
			$raw = get_site_transient( self::T_RAW );
			if ( ( $force || ! is_string( $raw ) ) && self::may_fetch() && self::backoff_over() ) {
				$fetched = self::fetch();
				if ( null !== $fetched ) {
					$raw = $fetched;
				}
			}
			self::$payload = is_string( $raw ) ? self::verify( $raw ) : null;
			return self::$payload;
		}

		private static function backoff_over() {
			$fail = get_site_option( self::O_FAIL );
			return ! is_array( $fail ) || empty( $fail['until'] ) || time() >= (int) $fail['until'];
		}

		/** Тело манифеста или null при сбое (тогда пауза растёт: 1, 6, 24 ч). */
		private static function fetch() {
			$r    = wp_remote_get( self::MANIFEST, array( 'timeout' => self::TIMEOUT, 'headers' => array( 'Accept' => 'application/json' ) ) );
			$body = is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
			if ( '' === $body || null === self::verify( $body ) ) {
				$fail  = get_site_option( self::O_FAIL );
				$count = is_array( $fail ) && isset( $fail['count'] ) ? (int) $fail['count'] : 0;
				$wait  = self::BACKOFF[ min( $count, count( self::BACKOFF ) - 1 ) ];
				update_site_option( self::O_FAIL, array( 'count' => $count + 1, 'until' => time() + $wait ) );
				return null;
			}
			delete_site_option( self::O_FAIL );
			set_site_transient( self::T_RAW, $body, self::TTL );
			return $body;
		}

		/** Подпись, формат и «не старее уже виденного». */
		private static function verify( $raw ) {
			$env = json_decode( $raw, true );
			if ( ! is_array( $env ) || 1 !== ( $env['v'] ?? 0 ) || ! is_string( $env['payload'] ?? null ) || ! is_string( $env['sig'] ?? null ) ) {
				return null;
			}
			$key = self::KEYS[ (string) ( $env['key'] ?? '' ) ] ?? '';
			$pub = base64_decode( $key, true );
			$sig = base64_decode( $env['sig'], true );
			$msg = base64_decode( $env['payload'], true );
			if ( ! $pub || ! $sig || false === $msg || 32 !== strlen( $pub ) || 64 !== strlen( $sig ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return null;
			}
			try {
				if ( ! sodium_crypto_sign_verify_detached( $sig, $msg, $pub ) ) {
					return null;
				}
			} catch ( \Throwable $e ) {
				return null;
			}
			$data = json_decode( $msg, true );
			if ( ! is_array( $data ) || ! is_array( $data['products'] ?? null ) || ! is_int( $data['issued'] ?? null ) ) {
				return null;
			}
			// старый манифест, подсунутый вместо нового, не принимаем
			$seen = (int) get_site_option( self::O_ISSUED, 0 );
			if ( $data['issued'] < $seen ) {
				return null;
			}
			if ( $data['issued'] > $seen ) {
				update_site_option( self::O_ISSUED, $data['issued'] );
			}
			return $data;
		}

		private static function channel() {
			$channel = defined( 'IMPIK_UPDATES_CHANNEL' ) ? IMPIK_UPDATES_CHANNEL : get_site_option( self::O_CHANNEL, 'stable' );
			return 'beta' === $channel ? 'beta' : 'stable';
		}

		/** Выпуск для этого сайта: [выпуск или null, причина отказа или ''] . */
		private function release( $force = false ) {
			$data    = self::payload( $force );
			$product = $data['products'][ $this->slug ] ?? null;
			if ( ! is_array( $product ) ) {
				return array( null, '' );
			}
			$rel = $product[ self::channel() ] ?? ( $product['stable'] ?? null );
			if ( ! is_array( $rel ) || empty( $rel['version'] ) || empty( $rel['sha256'] ) || 0 !== strpos( (string) ( $rel['package'] ?? '' ), 'https://' . self::HOST . '/' . $this->slug . '/' ) ) {
				return array( null, '' );
			}
			return array( $rel, $this->refusal( $rel, (array) ( $product['blocked'] ?? array() ) ) );
		}

		/** Почему выпуск не подходит сайту ('' — подходит). */
		private function refusal( array $rel, array $blocked ) {
			$version = (string) $rel['version'];
			$local   = get_site_option( self::O_BLOCKED, array() );
			if ( in_array( $version, $blocked, true ) ) {
				return 'версия ' . $version . ' отозвана';
			}
			if ( is_array( $local ) && in_array( $version, (array) ( $local[ $this->slug ] ?? array() ), true ) ) {
				return 'версия ' . $version . ' уже сломала этот сайт и была откачена';
			}
			if ( ! empty( $rel['requires_php'] ) && version_compare( PHP_VERSION, (string) $rel['requires_php'], '<' ) ) {
				return 'нужен PHP ' . $rel['requires_php'] . ', на сайте ' . PHP_VERSION;
			}
			$wp = (string) get_bloginfo( 'version' );
			if ( ! empty( $rel['requires'] ) && version_compare( $wp, (string) $rel['requires'], '<' ) ) {
				return 'нужен WordPress ' . $rel['requires'] . ', на сайте ' . $wp;
			}
			if ( ! empty( $rel['frame_contracts'] ) ) {
				$support  = get_theme_support( 'impik-frame' );
				$contract = is_array( $support ) && is_array( $support[0] ?? null ) ? (int) ( $support[0]['contract'] ?? 0 ) : 0;
				if ( $contract && ! in_array( $contract, array_map( 'intval', (array) $rel['frame_contracts'] ), true ) ) {
					return 'тема работает по договору Impik Frame ' . $contract . ', а новая версия — только по ' . implode( ', ', (array) $rel['frame_contracts'] );
				}
			}
			return '';
		}

		// ── WordPress ──────────────────────────────────────────────────────────

		public function check( $update, $plugin_data, $plugin_file ) {
			if ( $plugin_file !== $this->basename ) {
				return $update;
			}
			list( $rel, $reason ) = $this->release( isset( $_GET['force-check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $rel ) {
				return $update;
			}
			if ( $reason && ! version_compare( $rel['version'], $this->version, '>' ) ) {
				$reason = '';
			}
			return array(
				'id'            => self::HOST . '/' . $this->slug,
				'slug'          => $this->slug,
				'plugin'        => $this->basename,
				'version'       => (string) $rel['version'],
				'new_version'   => (string) $rel['version'],
				'url'           => 'https://impik.dev',
				'package'       => ( $reason || ! version_compare( $rel['version'], $this->version, '>' ) ) ? '' : (string) $rel['package'],
				'requires'      => (string) ( $rel['requires'] ?? '' ),
				'requires_php'  => (string) ( $rel['requires_php'] ?? '' ),
				'tested'        => (string) ( $rel['tested'] ?? '' ),
				'impik_refusal' => $reason,
			);
		}

		/** Причина под строкой плагина, если обновление есть, но не подходит. */
		public function row_message( $plugin_data, $response ) {
			$reason = is_object( $response ) && ! empty( $response->impik_refusal ) ? (string) $response->impik_refusal : '';
			if ( $reason ) {
				echo ' <strong>Обновление не подходит этому сайту: ' . esc_html( $reason ) . '.</strong>';
			}
		}

		public function details( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
				return $result;
			}
			list( $rel, $reason ) = $this->release();
			if ( ! $rel ) {
				return $result;
			}
			$data = get_plugin_data( $this->file, false, false );
			return (object) array(
				'name'          => $data['Name'],
				'slug'          => $this->slug,
				'version'       => $rel['version'],
				'author'        => '<a href="https://impik.dev">impik.dev</a>',
				'homepage'      => 'https://impik.dev',
				'requires'      => (string) ( $rel['requires'] ?? '' ),
				'requires_php'  => (string) ( $rel['requires_php'] ?? '' ),
				'tested'        => (string) ( $rel['tested'] ?? '' ),
				'download_link' => $reason ? '' : $rel['package'],
				'sections'      => array(
					'description' => esc_html( $data['Description'] ) . ( $reason ? '<p><strong>Обновление не подходит этому сайту: ' . esc_html( $reason ) . '.</strong></p>' : '' ),
					'changelog'   => wp_kses_post( (string) ( $rel['changelog'] ?? '' ) ),
				),
			);
		}

		/** Скачиваем архив сами и сверяем sha256 из подписанного манифеста. */
		public function verify_download( $reply, $package, $upgrader, $hook_extra = array() ) {
			if ( false !== $reply || 0 !== strpos( (string) $package, 'https://' . self::HOST . '/' . $this->slug . '/' ) ) {
				return $reply;
			}
			list( $rel, $reason ) = $this->release();
			if ( ! $rel || $package !== $rel['package'] ) {
				list( $rel, $reason ) = $this->release( true );
			}
			if ( ! $rel || $package !== $rel['package'] ) {
				return new \WP_Error( 'impik_unknown_package', 'Этого архива нет в подписанном манифесте impik.dev — обновление отменено.' );
			}
			if ( $reason ) {
				return new \WP_Error( 'impik_refused', 'Обновление не подходит этому сайту: ' . $reason . '.' );
			}
			$tmp = download_url( $package, 120 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			if ( ! hash_equals( strtolower( (string) $rel['sha256'] ), (string) hash_file( 'sha256', $tmp ) ) ) {
				wp_delete_file( $tmp );
				return new \WP_Error( 'impik_bad_hash', 'Архив обновления не совпал с контрольной суммой — обновление отменено.' );
			}
			return $tmp;
		}
	}
}
