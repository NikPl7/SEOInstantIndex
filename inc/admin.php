<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', static function (): void {
    $screen = add_management_page( 'SEO InstantIndex', 'SEO InstantIndex', 'manage_options', 'hopcott-indexnow', 'hcin_admin_page' );
    if ( $screen ) {
        add_action( 'load-' . $screen, static function (): void {
            wp_enqueue_style( 'hcin-admin', plugins_url( 'assets/admin.css', dirname( __DIR__ ) . '/seo-instantindex.php' ), [], HCIN_VERSION );
        } );
    }
} );

function hcin_admin_url( string $tab ): string {
    return admin_url( 'tools.php?page=hopcott-indexnow&tab=' . $tab );
}

function hcin_form_value( array $values, string $name ): string {
    return is_string( $values[$name] ?? null ) ? wp_unslash( $values[$name] ) : '';
}

function hcin_post_types(): array {
    $types = get_post_types( [ 'public' => true ], 'objects' );
    unset( $types['attachment'] );
    return array_filter( $types, 'is_post_type_viewable' );
}

function hcin_chosen_types( string $name, array $allowed ): array {
    $values = array_filter( (array) ( $_POST[$name] ?? [] ), 'is_string' );
    return array_values( array_intersect( $allowed, array_map( 'sanitize_key', wp_unslash( $values ) ) ) );
}

function hcin_save_settings(): array {
    $settings = hcin_settings();
    $key = isset( $_POST['indexnow_key'] ) ? trim( hcin_form_value( $_POST, 'indexnow_key' ) ) : $settings['indexnow_key'];
    $key_url = isset( $_POST['indexnow_key_url'] ) ? trim( hcin_form_value( $_POST, 'indexnow_key_url' ) ) : $settings['indexnow_key_url'];
    if ( $key !== '' && ! preg_match( '/^[a-zA-Z0-9-]{8,128}$/D', $key ) ) {
        return [ 'error', 'Ключ IndexNow должен содержать от 8 до 128 латинских букв, цифр или дефисов.' ];
    }
    if ( $key_url !== '' && ! hcin_valid_urls( [ $key_url ] ) ) {
        return [ 'error', 'Введите полный адрес файла ключа на домене этого сайта.' ];
    }
    $previous = $settings['google_sa_json'];
    $json = trim( hcin_form_value( $_POST, 'google_sa_json' ) );
    if ( ! empty( $_POST['google_sa_remove'] ) ) {
        $json = '';
    } elseif ( $json === '' ) {
        $json = $previous;
    } elseif ( ! hcin_service_account( $json ) ) {
        return [ 'error', 'Проверьте JSON сервисного аккаунта. Настройки остались прежними.' ];
    }

    $allowed = array_keys( hcin_post_types() );
    $saved = array_merge( $settings, [
        'indexnow_enabled' => ! empty( $_POST['indexnow_enabled'] ),
        'indexnow_key'     => $key,
        'indexnow_key_url' => $key_url,
        'google_enabled'   => ! empty( $_POST['google_enabled'] ),
        'google_sa_json'   => $json,
        'post_types'      => hcin_chosen_types( 'post_types', $allowed ),
        'google_post_types' => hcin_chosen_types( 'google_post_types', $allowed ),
    ] );
    if ( $saved !== $settings && ! update_option( HCIN_OPT, $saved ) ) {
        return [ 'error', 'Не удалось сохранить настройки. Повторите попытку.' ];
    }
    if ( $json !== $previous ) {
        delete_transient( 'hcin_google_token' );
        delete_transient( hcin_google_token_key( $previous ) );
    }
    return [ 'success', 'Настройки сохранены.' ];
}

function hcin_manual_submission( array $enabled ): array {
    global $wpdb;
    $with_google = ! empty( $_POST['submit_google'] );
    if ( ( ! $enabled['indexnow'] && ! $with_google ) || ( $with_google && ! $enabled['google'] ) ) {
        return [ 'error', 'Подключите нужный сервис в настройках, затем повторите отправку.' ];
    }
    $input = trim( hcin_form_value( $_POST, 'hcin_urls' ) );
    $lines = array_values( array_filter( preg_split( '/\r\n|\n|\r/', $input ), static function ( string $line ): bool {
        return trim( $line ) !== '';
    } ) );
    if ( ! $lines || count( $lines ) > 1000 ) {
        return [ 'error', 'Введите от 1 до 1 000 адресов, по одному на строку.' ];
    }
    $urls = hcin_valid_urls( $lines );
    if ( count( $urls ) !== count( array_unique( array_map( 'trim', $lines ) ) ) ) {
        return [ 'error', 'Проверьте список. Каждый URL должен начинаться с http:// или https://, вести на этот сайт и быть без пробелов и фрагмента (#).' ];
    }
    if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
        return [ 'error', 'Не удалось поставить адреса в очередь. Повторите попытку.' ];
    }
    $success = hcin_queue_submissions( $enabled['indexnow'] ? $urls : [], $with_google ? $urls : [], 'manual' );
    if ( ! $success || $wpdb->query( 'COMMIT' ) === false ) {
        $wpdb->query( 'ROLLBACK' );
        return [ 'error', 'Не удалось поставить адреса в очередь. Повторите попытку.' ];
    }
    return [ 'success', sprintf( 'В очереди URL: %s. Результаты появятся в журнале.', number_format_i18n( count( $urls ) ) ) ];
}

function hcin_bulk_submission( array $settings, array $enabled ): array {
    global $wpdb;
    if ( ( ! $enabled['indexnow'] || ! $settings['post_types'] ) && ( ! $enabled['google'] || ! $settings['google_post_types'] ) ) {
        return [ 'error', 'Выберите типы записей хотя бы для одного подключённого сервиса.' ];
    }
    $through = $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
    if ( ! $through || ! hcin_queue_job( 'scan', 'bulk', [ 'after' => 0, 'through' => (int) $through ] ) ) {
        return [ 'error', 'Не удалось поставить массовую отправку в очередь.' ];
    }
    return [ 'success', 'Массовая отправка в очереди. Результаты появятся в журнале.' ];
}

function hcin_flash_key(): string {
    return 'hcin_flash_' . get_current_user_id();
}

add_action( 'admin_init', static function (): void {
    $action = sanitize_key( hcin_form_value( $_POST, 'hcin_action' ) );
    if ( $action === '' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    check_admin_referer( 'hcin_nonce' );
    $settings = hcin_settings();
    $tab = 'settings';
    switch ( $action ) {
        case 'save_settings':
            $notice = hcin_save_settings();
            break;
        case 'generate_key':
            [ $ok, $message ] = hcin_generate_key();
            $notice = [ $ok ? 'success' : 'error', $message ];
            break;
        case 'check_key':
            $ok = hcin_check_key( hcin_key_url( $settings ), $settings['indexnow_key'] );
            $notice = $ok
                ? [ 'success', 'Файл доступен, ключ совпадает.' ]
                : [ 'error', 'Файл недоступен или ключ не совпадает.' ];
            break;
        case 'submit_custom':
            $tab = 'submit';
            $notice = hcin_manual_submission( hcin_connected( $settings ) );
            break;
        case 'submit_all':
            $tab = 'submit';
            $notice = hcin_bulk_submission( $settings, hcin_connected( $settings ) );
            break;
        case 'clear_log':
            $tab = 'log';
            delete_option( HCIN_LOG_OPT );
            $notice = [ 'success', 'Журнал очищен.' ];
            break;
        default:
            return;
    }
    set_transient( hcin_flash_key(), $notice, 60 );
    wp_safe_redirect( hcin_admin_url( $tab ) );
    exit;
} );

function hcin_nonce(): void {
    printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( 'hcin_nonce' ) ) );
}

function hcin_provider_status( bool $connected, bool $enabled ): void {
    $label = $connected ? 'Настроен' : ( $enabled ? 'Требует настройки' : 'Выключен' );
    ?>
    <span class="hcin_status <?php echo $connected ? 'hcin_status-on' : ''; ?>"><?php echo esc_html( $label ); ?></span>
    <?php
}

function hcin_type_fields( string $name, array $selected ): void {
    foreach ( hcin_post_types() as $slug => $type ) :
        ?>
        <label class="hcin_check">
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?>>
            <?php echo esc_html( $type->labels->singular_name ); ?>
        </label>
        <?php
    endforeach;
}

function hcin_settings_view( array $settings, array $enabled ): void {
    $account = hcin_service_account( $settings['google_sa_json'] );
    ?>
    <form method="post" id="hcin-key-actions">
        <?php hcin_nonce(); ?>
    </form>
    <form method="post">
        <?php hcin_nonce(); ?>
        <div class="hcin_grid">
            <section class="hcin_card" aria-labelledby="hcin-indexnow">
                <header class="hcin_card-head">
                    <h2 id="hcin-indexnow">IndexNow</h2>
                    <?php hcin_provider_status( $enabled['indexnow'], $settings['indexnow_enabled'] ); ?>
                </header>
                <p class="hcin_subtitle">Уведомления для Bing, Яндекса и других участников IndexNow.</p>
                <label class="hcin_check"><input type="checkbox" name="indexnow_enabled" value="1" <?php checked( $settings['indexnow_enabled'] ); ?>> Включить IndexNow</label>
                <label class="hcin_field" for="hcin-key">Ключ IndexNow</label>
                <input id="hcin-key" type="text" name="indexnow_key" value="<?php echo esc_attr( $settings['indexnow_key'] ); ?>" spellcheck="false" maxlength="128" autocomplete="off">
                <label class="hcin_field" for="hcin-key-url">Адрес файла ключа</label>
                <input id="hcin-key-url" type="url" name="indexnow_key_url" value="<?php echo esc_attr( $settings['indexnow_key_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/indexnow-key.txt' ) ); ?>">
                <div class="hcin_actions">
                    <button class="button" type="submit" name="hcin_action" value="generate_key" form="hcin-key-actions"><?php echo $settings['indexnow_key'] ? 'Создать новый ключ' : 'Создать ключ'; ?></button>
                    <?php if ( $settings['indexnow_key'] ) : ?>
                        <button class="button" type="submit" name="hcin_action" value="check_key" form="hcin-key-actions">Проверить файл</button>
                    <?php endif; ?>
                </div>
                <fieldset class="hcin_types">
                    <legend>Типы записей для автоотправки</legend>
                    <?php hcin_type_fields( 'post_types', $settings['post_types'] ); ?>
                </fieldset>
            </section>

            <section class="hcin_card" aria-labelledby="hcin-google">
                <header class="hcin_card-head">
                    <h2 id="hcin-google">Google Indexing API</h2>
                    <?php hcin_provider_status( $enabled['google'], $settings['google_enabled'] ); ?>
                </header>
                <p class="hcin_subtitle">Только для вакансий и видеотрансляций. <a href="https://developers.google.com/search/apis/indexing-api/v3/quickstart">Условия и настройка Google</a></p>
                <label class="hcin_check"><input type="checkbox" name="google_enabled" value="1" <?php checked( $settings['google_enabled'] ); ?>> Включить Google Indexing API</label>
                <?php if ( ! function_exists( 'openssl_sign' ) ) : ?>
                    <p class="hcin_subtitle">Для Google требуется расширение PHP OpenSSL.</p>
                <?php endif; ?>
                <label class="hcin_field" for="hcin-json">JSON сервисного аккаунта</label>
                <?php if ( $account ) : ?>
                    <p class="hcin_subtitle">Аккаунт <code><?php echo esc_html( $account['client_email'] ); ?></code>. Оставьте поле пустым, чтобы сохранить текущий ключ.</p>
                <?php endif; ?>
                <textarea id="hcin-json" name="google_sa_json" rows="4" spellcheck="false" autocomplete="off" placeholder="Вставьте содержимое JSON-файла"></textarea>
                <?php if ( $settings['google_sa_json'] ) : ?>
                    <label class="hcin_check"><input type="checkbox" name="google_sa_remove" value="1"> Удалить сохранённый ключ</label>
                <?php endif; ?>
                <fieldset class="hcin_types">
                    <legend>Типы записей для автоотправки</legend>
                    <p class="hcin_subtitle">Выбирайте типы, в которых публикуются только вакансии или трансляции с нужной разметкой.</p>
                    <?php hcin_type_fields( 'google_post_types', $settings['google_post_types'] ); ?>
                </fieldset>
            </section>
        </div>
        <div class="hcin_actions">
            <button class="button button-primary" type="submit" name="hcin_action" value="save_settings">Сохранить настройки</button>
        </div>
    </form>
    <?php
}

function hcin_submit_view( array $settings, array $enabled ): void {
    $can_bulk = ( $enabled['indexnow'] && $settings['post_types'] ) || ( $enabled['google'] && $settings['google_post_types'] );
    ?>
    <div class="hcin_grid">
        <section class="hcin_card">
            <h2>Отправить список URL</h2>
            <form method="post">
                <?php hcin_nonce(); ?>
                <label class="hcin_field" for="hcin-urls">Адреса этого сайта, по одному на строку</label>
                <textarea id="hcin-urls" name="hcin_urls" rows="6" required spellcheck="false"></textarea>
                <?php if ( $enabled['indexnow'] ) : ?><p class="hcin_subtitle">Адреса из списка уйдут в IndexNow.</p><?php endif; ?>
                <?php if ( $enabled['google'] ) : ?>
                    <label class="hcin_check"><input type="checkbox" name="submit_google" value="1"> Отправить в Google. Все URL в списке ведут на вакансии или трансляции с нужной разметкой.</label>
                <?php endif; ?>
                <div class="hcin_actions">
                    <button class="button button-primary" type="submit" name="hcin_action" value="submit_custom" <?php disabled( ! in_array( true, $enabled, true ) ); ?>>Поставить в очередь</button>
                </div>
            </form>
        </section>
        <section class="hcin_card">
            <h2>Массовая отправка</h2>
            <p class="hcin_subtitle">Плагин отправит опубликованные записи выбранных типов. Списки для IndexNow и Google берутся из настроек.</p>
            <?php if ( ! $can_bulk ) : ?>
                <p>Подключите сервис и выберите типы записей в настройках.</p>
            <?php endif; ?>
            <form method="post" class="hcin_actions">
                <?php hcin_nonce(); ?>
                <button class="button" type="submit" name="hcin_action" value="submit_all" <?php disabled( ! $can_bulk ); ?>>Поставить все в очередь</button>
            </form>
        </section>
    </div>
    <?php
}

function hcin_log_view( array $log, int $pending ): void {
    $providers = [ 'indexnow' => 'IndexNow', 'google' => 'Google', 'scan' => 'Список URL', 'queue' => 'Очередь' ];
    $sources = [ 'auto' => 'Авто', 'manual' => 'Вручную', 'bulk' => 'Массовая' ];
    ?>
    <section class="hcin_card">
        <header class="hcin_card-head">
            <h2>Журнал отправки</h2>
            <?php if ( $log ) : ?>
                <form method="post">
                    <?php hcin_nonce(); ?>
                    <button class="button" type="submit" name="hcin_action" value="clear_log">Очистить журнал</button>
                </form>
            <?php endif; ?>
        </header>
        <?php if ( ! $log ) : ?>
            <p class="hcin_subtitle"><?php echo $pending ? 'Отправка ещё идёт. Записи появятся после ответа сервисов.' : 'Записей пока нет. Здесь появятся результаты отправки.'; ?></p>
        <?php else : ?>
            <div class="hcin_table">
                <table>
                    <thead><tr><th scope="col">Время</th><th scope="col">Источник</th><th scope="col">Сервис</th><th scope="col">Результат</th><th scope="col">URL</th></tr></thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) :
                            $urls = (array) ( $entry['urls'] ?? [] );
                            foreach ( (array) ( $entry['results'] ?? [] ) as $provider => $result ) :
                                $code = (int) ( $result['http_code'] ?? 0 );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $sources[$entry['source']] ?? '—' ); ?></td>
                                    <td><?php echo esc_html( $providers[$provider] ?? $provider ); ?></td>
                                    <td>
                                        <strong class="hcin_result <?php echo empty( $result['ok'] ) ? 'hcin_result-error' : 'hcin_result-ok'; ?>">
                                            <?php echo esc_html( empty( $result['ok'] ) ? 'Ошибка' : (
                                                $provider === 'indexnow' && $code === 202 ? 'Ключ на проверке' : 'Принято'
                                            ) ); ?>
                                        </strong>
                                        <?php if ( $code ) : ?><span class="hcin_http">HTTP <?php echo (int) $code; ?></span><?php endif; ?>
                                        <?php if ( isset( $result['sent'], $result['total'] ) ) : ?>
                                            <span class="hcin_http"><?php echo esc_html( sprintf( '%d из %d URL', $result['sent'], $result['total'] ) ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $result['error'] ) ) : ?><div><?php echo esc_html( wp_html_excerpt( $result['error'], 140, '…' ) ); ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php foreach ( array_slice( $urls, 0, 3 ) as $url ) : ?><div><?php echo esc_html( $url ); ?></div><?php endforeach; ?>
                                        <?php if ( count( $urls ) > 3 ) : ?><span class="hcin_http"><?php echo esc_html( 'Ещё ' . ( count( $urls ) - 3 ) ); ?></span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach;
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function hcin_admin_page(): void {
    global $wpdb;
    $settings = hcin_settings();
    $enabled = hcin_connected( $settings );
    $tabs = [ 'settings' => 'Настройки', 'submit' => 'Отправка', 'log' => 'Журнал' ];
    $tab = sanitize_key( hcin_form_value( $_GET, 'tab' ) );
    $tab = isset( $tabs[$tab] ) ? $tab : 'settings';
    $notice = get_transient( hcin_flash_key() );
    if ( $notice ) {
        delete_transient( hcin_flash_key() );
    }
    $table = hcin_queue_table();
    $pending = get_option( 'hcin_queue_schema' ) === HCIN_QUEUE_SCHEMA ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) : 0;
    $log = array_values( array_filter( (array) get_option( HCIN_LOG_OPT, [] ), static function ( $entry ): bool {
        return ! empty( $entry['results'] );
    } ) );
    ?>
    <div class="wrap hcin_admin">
        <header class="hcin_header">
            <div>
                <a class="hcin_brand" href="https://impik.dev">impik.dev</a>
                <h1>SEO InstantIndex</h1>
                <p>Уведомления поисковым системам о новых и обновлённых страницах.</p>
            </div>
            <div class="hcin_queue">
                <strong><?php echo esc_html( number_format_i18n( $pending ) ); ?></strong>
                <span>Задач в очереди</span>
            </div>
        </header>
        <nav class="hcin_nav" aria-label="Разделы SEO InstantIndex">
            <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( hcin_admin_url( $key ) ); ?>" <?php if ( $tab === $key ) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> hcin_notice"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>
        <?php if ( $tab === 'settings' ) {
            hcin_settings_view( $settings, $enabled );
        } elseif ( $tab === 'submit' ) {
            hcin_submit_view( $settings, $enabled );
        } else {
            hcin_log_view( $log, $pending );
        } ?>
    </div>
    <?php
}
