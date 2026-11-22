<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', static function (): void {
    $screen = add_management_page( 'SEO InstantIndex', 'SEO InstantIndex', 'manage_options', 'hopcott-indexnow', 'hcin_admin_page' );
    if ( $screen ) {
        add_action( 'load-' . $screen, static function (): void {
            $plugin = dirname( __DIR__ ) . '/seo-instantindex.php';
            wp_enqueue_style( 'hcin-admin', plugins_url( 'assets/admin.css', $plugin ), [], HCIN_VERSION );
            wp_enqueue_script( 'hcin-help', plugins_url( 'assets/help.js', $plugin ), [], HCIN_VERSION, true );
            $tokens = hcin_admin_design_css( (array) apply_filters( 'hcin_admin_design_tokens', [] ) );
            if ( $tokens !== '' ) {
                wp_add_inline_style( 'hcin-admin', '.hcin_admin{' . $tokens . '}' );
            }
        } );
    }
} );

function hcin_admin_design_css( array $tokens ): string {
    $css = '';
    $accent = $tokens['accent'] ?? null;
    if ( is_string( $accent ) && preg_match( '/^#[a-f0-9]{6}$/iD', $accent ) ) {
        $rgb = array_map( static function ( string $hex ): float {
            $channel = hexdec( $hex ) / 255;
            return $channel <= 0.04045 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
        }, str_split( substr( $accent, 1 ), 2 ) );
        $luminance = $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
        $css .= '--hcin-accent:' . $accent . ';--hcin-accent-ink:' . ( $luminance > 0.179 ? '#000' : '#fff' ) . ';';
    }
    $radius = $tokens['radius'] ?? null;
    if ( is_int( $radius ) && $radius >= 4 && $radius <= 24 ) {
        $css .= '--hcin-radius:' . $radius . 'px;';
    }
    $font = $tokens['font_family'] ?? null;
    if ( is_string( $font ) && preg_match( '/^[a-z0-9][a-z0-9 ,_-]{0,119}$/iD', $font ) ) {
        $css .= '--hcin-font:' . $font . ';';
    }
    return $css;
}

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

function hcin_help( string $label, string $text ): void {
    $id = wp_unique_id( 'hcin-help-' );
    ?>
    <span class="hcin_help" data-hcin-help>
        <button class="hcin_help-trigger" type="button" aria-label="<?php echo esc_attr( $label ); ?>"
            aria-describedby="<?php echo esc_attr( $id ); ?>" aria-controls="<?php echo esc_attr( $id ); ?>" aria-expanded="false">?</button>
        <span class="hcin_help-text" id="<?php echo esc_attr( $id ); ?>" role="tooltip" hidden><?php echo esc_html( $text ); ?></span>
    </span>
    <?php
}

function hcin_provider_status( bool $connected, bool $enabled ): void {
    $label = $connected ? 'Настроен' : ( $enabled ? 'Нужна настройка' : 'Выключен' );
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
    $has_key = $settings['indexnow_key'] !== '';
    $account = hcin_service_account( $settings['google_sa_json'] );
    ?>
    <form method="post" id="hcin-key-actions">
        <?php hcin_nonce(); ?>
    </form>
    <form method="post" novalidate>
        <?php hcin_nonce(); ?>
        <div class="hcin_grid">
            <section class="hcin_card" aria-labelledby="hcin-indexnow">
                <header class="hcin_card-head">
                    <h2 id="hcin-indexnow">IndexNow</h2>
                    <?php hcin_provider_status( $enabled['indexnow'], $settings['indexnow_enabled'] ); ?>
                </header>
                <p class="hcin_subtitle">Сообщает Яндексу, Bing и другим участникам IndexNow об изменениях на сайте.</p>
                <div class="hcin_setup">
                    <div class="hcin_label">
                        <h3>Ключ IndexNow</h3>
                        <?php hcin_help( 'Зачем нужен ключ IndexNow', 'Поисковые системы проверяют файл ключа на вашем сайте. Так они убеждаются, что запрос действительно относится к вашему домену.' ); ?>
                    </div>
                    <?php if ( ! $has_key ) : ?>
                        <p>Начните с создания ключа. Плагин сам разместит файл на сайте и проверит, доступен ли он.</p>
                        <button class="button button-primary" type="submit" name="hcin_action" value="generate_key" form="hcin-key-actions">Создать ключ</button>
                    <?php else : ?>
                        <p>Ключ сохранён. Проверьте файл, если меняли домен или переносили сайт.</p>
                        <button class="button" type="submit" name="hcin_action" value="check_key" form="hcin-key-actions">Проверить файл ключа</button>
                    <?php endif; ?>
                </div>

                <details class="hcin_details">
                    <summary><?php echo $has_key ? 'Посмотреть или изменить ключ' : 'Указать свой ключ'; ?></summary>
                    <p class="hcin_subtitle">Разместите на сайте текстовый файл, в котором есть только ключ. Укажите его полный адрес.</p>
                    <label class="hcin_field" for="hcin-key">Ключ IndexNow</label>
                    <input id="hcin-key" type="text" name="indexnow_key" value="<?php echo esc_attr( $settings['indexnow_key'] ); ?>" spellcheck="false" maxlength="128" autocomplete="off">
                    <div class="hcin_label hcin_field">
                        <label for="hcin-key-url">Адрес файла ключа</label>
                        <?php hcin_help( 'Как указать адрес файла ключа', 'Вставьте ссылку на файл с ключом на домене этого сайта. Если файл называется так же, как ключ, и лежит в корне сайта, поле можно оставить пустым.' ); ?>
                    </div>
                    <input id="hcin-key-url" type="url" name="indexnow_key_url" value="<?php echo esc_attr( $settings['indexnow_key_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/indexnow-key.txt' ) ); ?>">
                    <?php if ( $has_key ) : ?>
                        <div class="hcin_actions">
                            <button class="button" type="submit" name="hcin_action" value="generate_key" form="hcin-key-actions">Создать новый ключ</button>
                        </div>
                    <?php endif; ?>
                </details>
                <div class="hcin_card-settings">
                    <label class="hcin_check"><input type="checkbox" name="indexnow_enabled" value="1" <?php checked( $settings['indexnow_enabled'] ); ?>> Включить IndexNow</label>
                    <fieldset class="hcin_types">
                        <legend>Что отправлять автоматически</legend>
                        <p class="hcin_subtitle">При публикации и обновлении выбранных типов.</p>
                        <?php hcin_type_fields( 'post_types', $settings['post_types'] ); ?>
                    </fieldset>
                </div>
            </section>

            <section class="hcin_card" aria-labelledby="hcin-google">
                <header class="hcin_card-head">
                    <h2 id="hcin-google">Google Indexing API</h2>
                    <?php hcin_provider_status( $enabled['google'], $settings['google_enabled'] ); ?>
                </header>
                <p class="hcin_subtitle">Подходит только для вакансий и видеотрансляций с разметкой, которую принимает Google.</p>
                <div class="hcin_setup">
                    <div class="hcin_label">
                        <h3>Файл доступа Google</h3>
                        <?php hcin_help( 'Что такое JSON сервисного аккаунта', 'JSON — формат текстового файла. Здесь он содержит адрес сервисного аккаунта и закрытый ключ, которым плагин подтверждает доступ к Google.' ); ?>
                    </div>
                    <p id="hcin-json-hint"><?php if ( $account ) : ?>
                        Аккаунт <code><?php echo esc_html( $account['client_email'] ); ?></code>. Пустое поле сохранит текущий ключ.
                    <?php else : ?>
                        Скачайте ключ для сервисного аккаунта в Google Cloud и вставьте сюда содержимое файла.
                    <?php endif; ?></p>
                    <?php if ( ! function_exists( 'openssl_sign' ) ) : ?>
                        <p>Для подключения попросите хостинг включить расширение OpenSSL для PHP.</p>
                    <?php endif; ?>
                    <label class="screen-reader-text" for="hcin-json">Содержимое файла JSON</label>
                    <textarea id="hcin-json" name="google_sa_json" rows="3" aria-describedby="hcin-json-hint" spellcheck="false" autocomplete="off" placeholder="Вставьте содержимое файла .json"></textarea>
                    <?php if ( $settings['google_sa_json'] ) : ?>
                        <label class="hcin_check"><input type="checkbox" name="google_sa_remove" value="1"> Удалить сохранённый ключ</label>
                    <?php endif; ?>
                </div>
                <details class="hcin_details">
                    <summary>Как подключить Google</summary>
                    <ol>
                        <li>Включите Indexing API в <a href="https://console.cloud.google.com/">Google Cloud</a>.</li>
                        <li>Создайте сервисный аккаунт и скачайте его ключ в формате JSON.</li>
                        <li>Добавьте адрес аккаунта как владельца сайта в <a href="https://search.google.com/search-console">Google Search Console</a>.</li>
                        <li>Вставьте содержимое файла и сохраните настройки.</li>
                    </ol>
                    <a href="https://developers.google.com/search/apis/indexing-api/v3/quickstart">Инструкция Google</a>
                </details>
                <div class="hcin_card-settings">
                    <label class="hcin_check"><input type="checkbox" name="google_enabled" value="1" <?php checked( $settings['google_enabled'] ); ?>> Включить Google Indexing API</label>
                    <fieldset class="hcin_types">
                        <legend>Что отправлять автоматически</legend>
                        <p class="hcin_subtitle">Выбирайте типы, где публикуются только подходящие вакансии или трансляции.</p>
                        <?php hcin_type_fields( 'google_post_types', $settings['google_post_types'] ); ?>
                    </fieldset>
                </div>
            </section>
        </div>
        <div class="hcin_save">
            <button class="button button-primary" type="submit" name="hcin_action" value="save_settings">Сохранить настройки</button>
        </div>
    </form>
    <?php
}

function hcin_submit_view( array $settings, array $enabled ): void {
    $can_bulk = ( $enabled['indexnow'] && $settings['post_types'] ) || ( $enabled['google'] && $settings['google_post_types'] );
    $can_submit = in_array( true, $enabled, true );
    ?>
    <div class="hcin_grid">
        <section class="hcin_card" aria-labelledby="hcin-manual">
            <h2 id="hcin-manual">Отправить список адресов</h2>
            <p class="hcin_subtitle">Подходит, если вы обновили несколько страниц и хотите сообщить об этом поисковым системам.</p>
            <?php if ( $can_submit ) : ?>
                <form method="post">
                    <?php hcin_nonce(); ?>
                    <div class="hcin_field hcin_label">
                        <label for="hcin-urls">Адреса этого сайта</label>
                        <?php hcin_help( 'В каком формате вводить адреса', 'Скопируйте полный адрес из браузера, включая https:// или http://. Вставьте по одному адресу на строку. За раз можно добавить до 1 000 адресов.' ); ?>
                    </div>
                    <textarea id="hcin-urls" name="hcin_urls" rows="6" required spellcheck="false"></textarea>
                    <p class="hcin_subtitle">По одному адресу на строку.<?php if ( $enabled['indexnow'] ) : ?> Весь список уйдёт в IndexNow.<?php endif; ?></p>
                    <?php if ( $enabled['google'] ) : ?>
                        <label class="hcin_check"><input type="checkbox" name="submit_google" value="1"> Отправить в Google. Все URL в списке ведут на вакансии или трансляции с нужной разметкой.</label>
                    <?php endif; ?>
                    <div class="hcin_actions">
                        <button class="button button-primary" type="submit" name="hcin_action" value="submit_custom">Поставить в очередь</button>
                    </div>
                </form>
            <?php else : ?>
                <div class="hcin_actions">
                    <a class="button button-primary" href="<?php echo esc_url( hcin_admin_url( 'settings' ) ); ?>">Подключить сервис</a>
                </div>
            <?php endif; ?>
        </section>
        <section class="hcin_card" aria-labelledby="hcin-bulk">
            <h2 id="hcin-bulk">Массовая отправка</h2>
            <p class="hcin_subtitle">Поставит в очередь все опубликованные записи тех типов, которые вы выбрали для каждого сервиса.</p>
            <?php if ( ! $can_bulk ) : ?>
                <p>Подключите сервис и <a href="<?php echo esc_url( hcin_admin_url( 'settings' ) ); ?>">выберите типы записей</a>.</p>
            <?php else : ?>
                <form method="post" class="hcin_actions">
                    <?php hcin_nonce(); ?>
                    <button class="button" type="submit" name="hcin_action" value="submit_all">Поставить все в очередь</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

function hcin_response_help( string $provider, int $code ): string {
    $messages = [
        200 => 'Сервис принял уведомление. Когда страница появится в поиске, решает сам поисковик.',
        202 => $provider === 'indexnow'
            ? 'Сервис принял запрос. IndexNow ещё проверяет файл ключа на вашем сайте.'
            : 'Сервис принял запрос в обработку.',
        400 => 'Сервис отклонил запрос. Проверьте, что адреса введены полностью и без опечаток.',
        401 => 'Сервис отклонил доступ. Проверьте ключ в настройках плагина.',
        403 => $provider === 'indexnow'
            ? 'IndexNow не подтвердил ключ. Проверьте адрес файла и убедитесь, что в файле указан текущий ключ.'
            : 'Google не дал доступ. Проверьте, что сервисный аккаунт добавлен как владелец сайта в Search Console.',
        422 => 'Сервис отклонил список. Проверьте, что адреса относятся к домену сайта.',
        429 => 'Сервис исчерпал лимит запросов. Подождите, пока лимит обновится, и повторите отправку.',
    ];
    return $messages[ $code ] ?? ( $code >= 500
        ? 'Сервис вернул ошибку. Повторите отправку позже.'
        : 'Это код ответа от поискового сервиса. По нему можно уточнить причину в документации сервиса.' );
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
            <div class="hcin_table" role="region" aria-label="Результаты отправки по сервисам" tabindex="0">
                <table role="table">
                    <thead role="rowgroup"><tr role="row"><th scope="col">Время</th><th scope="col">Источник</th><th scope="col">Сервис</th><th scope="col">Результат</th><th scope="col">Адреса</th></tr></thead>
                    <tbody role="rowgroup">
                        <?php foreach ( $log as $entry ) :
                            $urls = (array) ( $entry['urls'] ?? [] );
                            foreach ( (array) ( $entry['results'] ?? [] ) as $provider => $result ) :
                                $code = (int) ( $result['http_code'] ?? 0 );
                                ?>
                                <tr role="row">
                                    <td role="cell"><span class="hcin_table-label" aria-hidden="true">Время</span><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                    <td role="cell"><span class="hcin_table-label" aria-hidden="true">Источник</span><?php echo esc_html( $sources[$entry['source']] ?? '—' ); ?></td>
                                    <td role="cell"><span class="hcin_table-label" aria-hidden="true">Сервис</span><?php echo esc_html( $providers[$provider] ?? $provider ); ?></td>
                                    <td role="cell">
                                        <span class="hcin_table-label" aria-hidden="true">Результат</span>
                                        <strong class="hcin_result <?php echo empty( $result['ok'] ) ? 'hcin_result-error' : 'hcin_result-ok'; ?>">
                                            <?php echo esc_html( empty( $result['ok'] ) ? 'Ошибка' : (
                                                $provider === 'indexnow' && $code === 202 ? 'Ключ на проверке' : 'Принято'
                                            ) ); ?>
                                        </strong>
                                        <?php if ( $code ) : ?>
                                            <div class="hcin_http hcin_label">
                                                HTTP <?php echo (int) $code; ?>
                                                <?php hcin_help( 'Что означает HTTP ' . $code, hcin_response_help( $provider, $code ) ); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ( isset( $result['sent'], $result['total'] ) ) : ?>
                                            <span class="hcin_http"><?php echo esc_html( sprintf( '%d из %d URL', $result['sent'], $result['total'] ) ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $result['error'] ) ) : ?><div><?php echo esc_html( wp_html_excerpt( $result['error'], 140, '…' ) ); ?></div><?php endif; ?>
                                    </td>
                                    <td role="cell">
                                        <span class="hcin_table-label" aria-hidden="true">Адреса</span>
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
    ?>
    <div class="wrap hcin_admin">
        <header class="hcin_header">
            <div>
                <a class="hcin_brand" href="https://impik.dev">impik.dev</a>
                <h1>SEO InstantIndex</h1>
                <p>Сообщайте поисковым системам о новых и обновлённых страницах.</p>
            </div>
            <div class="hcin_queue">
                <strong><?php echo esc_html( number_format_i18n( $pending ) ); ?></strong>
                <span class="hcin_label">Задач в очереди
                    <?php hcin_help( 'Как работает очередь', 'Плагин отправляет адреса в фоне. Когда сервис ответит, результат появится в журнале.' ); ?>
                </span>
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
            $log = array_filter( (array) get_option( HCIN_LOG_OPT, [] ), static function ( $entry ): bool {
                return ! empty( $entry['results'] );
            } );
            hcin_log_view( $log, $pending );
        } ?>
    </div>
    <?php
}
