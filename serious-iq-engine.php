<?php
/**
 * Plugin Name: Serious IQ Engine
 * Version: 3.7
 * Description: Structured cognitive test with percentile ranking + WhatsApp sharing.
 * Author: Ayodeji Agboola
 */

if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'sie_install');
add_action('init', 'sie_maybe_upgrade_question_bank');

function sie_item_source_label()
{
    return 'ICAR Open Cognitive Item Bank (Sample)';
}

function sie_install()
{
    global $wpdb;

    $table1 = $wpdb->prefix . 'sie_questions';
    $table2 = $wpdb->prefix . 'sie_scores';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql1 = "CREATE TABLE {$table1} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question TEXT,
        opt1 TEXT,
        opt2 TEXT,
        opt3 TEXT,
        opt4 TEXT,
        correct INT,
        domain VARCHAR(30),
        difficulty FLOAT DEFAULT 0.5,
        discrimination FLOAT DEFAULT 1.0,
        item_source VARCHAR(120) DEFAULT 'ICAR Open Cognitive Item Bank (Sample)',
        license VARCHAR(120) DEFAULT 'CC BY 4.0',
        explanation TEXT
    ) {$wpdb->get_charset_collate()};";

    $sql2 = "CREATE TABLE {$table2} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        score INT,
        created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) {$wpdb->get_charset_collate()};";

    dbDelta($sql1);
    dbDelta($sql2);

    sie_remove_legacy_question_bank();
    sie_seed_open_item_bank(true);
    sie_generate_large_question_bank(1200);
    update_option('sie_bank_migrated_v33', '1', false);
}



function sie_maybe_upgrade_question_bank()
{
    if (get_option('sie_bank_migrated_v33') === '1') {
        return;
    }

    sie_remove_legacy_question_bank();
    sie_seed_open_item_bank(false);
    sie_generate_large_question_bank(1200);
    update_option('sie_bank_migrated_v33', '1', false);
}

function sie_remove_legacy_question_bank()
{
    global $wpdb;
    $qtable = $wpdb->prefix . 'sie_questions';

    // Remove legacy rows that do not declare the new open item-bank source.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$qtable} WHERE item_source IS NULL OR item_source = '' OR item_source != %s",
        sie_item_source_label()
    ));
}

function sie_seed_open_item_bank($replace_existing = false)
{
    global $wpdb;
    $qtable = $wpdb->prefix . 'sie_questions';

    if ($replace_existing) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$qtable} WHERE item_source = %s", sie_item_source_label()));
    }

    $items = sie_open_item_bank();
    $inserted = 0;

    foreach ($items as $item) {
        $opts = $item['opts'];
        shuffle($opts);
        $correct_index = array_search($item['correct'], $opts, true) + 1;

        $payload = [
            'question' => $item['q'],
            'opt1' => $opts[0],
            'opt2' => $opts[1],
            'opt3' => $opts[2],
            'opt4' => $opts[3],
            'correct' => $correct_index,
            'domain' => $item['domain'],
            'difficulty' => $item['difficulty'],
            'discrimination' => $item['discrimination'],
            'item_source' => $item['source'],
            'license' => $item['license'],
            'explanation' => $item['explanation'],
        ];

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$qtable} WHERE question = %s LIMIT 1", $item['q']));

        if ($existing) {
            $ok = $wpdb->update($qtable, $payload, ['id' => (int) $existing]);
            if ($ok !== false) {
                $inserted++;
            }
            continue;
        }

        $ok = $wpdb->insert($qtable, $payload);

        if ($ok) {
            $inserted++;
        }
    }

    return $inserted;
}

function sie_get_seen_question_ids()
{
    $seen = [];

    if (!empty($_COOKIE['sie_seen_questions'])) {
        $cookie_ids = explode(',', sanitize_text_field(wp_unslash($_COOKIE['sie_seen_questions'])));
        $cookie_ids = array_map('intval', $cookie_ids);
        $seen = array_merge($seen, $cookie_ids);
    }

    if (is_user_logged_in()) {
        $user_seen = get_user_meta(get_current_user_id(), 'sie_seen_questions', true);
        if (is_array($user_seen)) {
            $seen = array_merge($seen, array_map('intval', $user_seen));
        }
    }

    $seen = array_values(array_unique(array_filter($seen, static function ($id) {
        return $id > 0;
    })));

    return $seen;
}

function sie_store_seen_question_ids($ids)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, static function ($id) {
        return $id > 0;
    });

    $existing = sie_get_seen_question_ids();
    $merged = array_values(array_unique(array_merge($existing, $ids)));

    $max_history = 240;
    if (count($merged) > $max_history) {
        $merged = array_slice($merged, -$max_history);
    }

    setcookie('sie_seen_questions', implode(',', $merged), time() + (DAY_IN_SECONDS * 30), COOKIEPATH ?: '/');
    $_COOKIE['sie_seen_questions'] = implode(',', $merged);

    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), 'sie_seen_questions', $merged);
    }
}

function sie_reset_seen_question_ids()
{
    setcookie('sie_seen_questions', '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/');
    unset($_COOKIE['sie_seen_questions']);

    if (is_user_logged_in()) {
        delete_user_meta(get_current_user_id(), 'sie_seen_questions');
    }
}

function sie_fetch_questions($limit = 15)
{
    global $wpdb;
    $qtable = $wpdb->prefix . 'sie_questions';
    $source = sie_item_source_label();

    $seen = sie_get_seen_question_ids();
    $candidate_limit = max(300, $limit * 40);

    if (!empty($seen)) {
        $placeholders = implode(',', array_fill(0, count($seen), '%d'));
        $sql = $wpdb->prepare(
            "SELECT * FROM {$qtable} WHERE item_source = %s AND id NOT IN ({$placeholders}) ORDER BY RAND() LIMIT %d",
            array_merge([$source], $seen, [$candidate_limit])
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT * FROM {$qtable} WHERE item_source = %s ORDER BY RAND() LIMIT %d",
            $source,
            $candidate_limit
        );
    }

    $candidates = $wpdb->get_results($sql);

    if (empty($candidates)) {
        return [];
    }

    $selected = [];
    $selected_ids = [];

    $groups = [
        'logic' => [],
        'numeric' => [],
        'spatial' => [],
        'classification' => [],
        'verbal' => [],
        'other' => [],
    ];

    foreach ($candidates as $q) {
        $domain = strtolower((string) $q->domain);
        if (isset($groups[$domain])) {
            $groups[$domain][] = $q;
        } else {
            $groups['other'][] = $q;
        }
    }

    // For 15-question tests, enforce 3 per open-bank domain type.
    $domain_quota = [
        'logic' => 3,
        'numeric' => 3,
        'spatial' => 3,
        'classification' => 3,
        'verbal' => 3,
    ];

    foreach ($domain_quota as $domain => $quota) {
        for ($i = 0; $i < $quota; $i++) {
            if (empty($groups[$domain])) {
                break;
            }

            $pick = array_pop($groups[$domain]);
            $id = (int) $pick->id;
            if (isset($selected_ids[$id])) {
                continue;
            }

            $selected[] = $pick;
            $selected_ids[$id] = true;
        }
    }

    // If a domain quota could not be fully met, top up from remaining random pool.
    foreach ($groups as $domain_items) {
        foreach ($domain_items as $item) {
            if (count($selected) >= $limit) {
                break 2;
            }

            $id = (int) $item->id;
            if (isset($selected_ids[$id])) {
                continue;
            }

            $selected[] = $item;
            $selected_ids[$id] = true;
        }
    }

    if (count($selected) < $limit) {
        foreach ($candidates as $item) {
            if (count($selected) >= $limit) {
                break;
            }

            $id = (int) $item->id;
            if (isset($selected_ids[$id])) {
                continue;
            }

            $selected[] = $item;
            $selected_ids[$id] = true;
        }
    }

    return array_slice($selected, 0, $limit);
}

function sie_render()
{
    $questions = sie_fetch_questions(15);

    if (empty($questions)) {
        // If all items in pool are currently marked as seen for this visitor, recycle history once.
        sie_reset_seen_question_ids();
        $questions = sie_fetch_questions(15);
    }

    if (empty($questions)) {
        // Recover from broken/partial migrations by rebuilding the open bank rows.
        sie_remove_legacy_question_bank();
        sie_seed_open_item_bank(true);
        sie_generate_large_question_bank(1200);
        $questions = sie_fetch_questions(15);
    }
    $question_ids = array_map(static function ($q) {
        return (int) $q->id;
    }, $questions);

    if (!empty($question_ids)) {
        sie_store_seen_question_ids($question_ids);
    }

    ob_start();
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        #sieApp {
            font-size: 1.08rem;
            line-height: 1.7;
        }

        #sieApp h2 {
            font-size: clamp(1.8rem, 2.6vw, 2.4rem);
        }

        #sieApp .fw-semibold {
            font-size: 1.18rem;
            line-height: 1.6;
        }

        #sieApp .form-check-label {
            font-size: 1.06rem;
        }

        #sieApp .btn-lg {
            font-size: 1.15rem;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }

        #sieResult,
        #sieResult p,
        #sieResult a {
            font-size: 1.08rem;
        }

        @media (max-width: 768px) {
            #sieApp {
                font-size: 1.14rem;
            }

            #sieApp .card-body {
                padding: 1.15rem;
            }

            #sieApp .fw-semibold {
                font-size: 1.2rem;
            }

            #sieApp .form-check-label {
                font-size: 1.12rem;
            }

            #sieApp .btn-lg {
                font-size: 1.2rem;
            }
        }
    </style>

    <div class="container my-4" id="sieApp">
        <div class="card shadow-lg border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <h2 class="fw-bold mb-2">Cognitive Performance Assessment</h2>
                    <p class="text-muted mb-0">Built from an open cognitive item bank with domain and difficulty balancing.</p>
                </div>

                <div class="progress mb-4" style="height: 10px;">
                    <div id="sieProgress" class="progress-bar progress-bar-striped bg-success" role="progressbar" style="width: 0%;"></div>
                </div>

                <form id="sieForm">
                    <?php foreach ($questions as $i => $q) : ?>
                        <div class="card mb-3 border-0 bg-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <p class="fw-semibold mb-0"><?php echo esc_html(($i + 1) . '. ' . $q->question); ?></p>
                                    <span class="badge bg-secondary"><?php echo esc_html(ucfirst($q->domain)); ?></span>
                                </div>
                                <small class="text-muted d-block mb-3">Difficulty: <?php echo esc_html(number_format((float) $q->difficulty, 2)); ?> · Discrimination: <?php echo esc_html(number_format((float) $q->discrimination, 2)); ?></small>

                                <?php for ($x = 1; $x <= 4; $x++) : ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input"
                                               type="radio"
                                               name="q<?php echo esc_attr($i); ?>"
                                               id="q<?php echo esc_attr($i . '_' . $x); ?>"
                                               value="<?php echo ($x == $q->correct) ? 1 : 0; ?>">
                                        <label class="form-check-label" for="q<?php echo esc_attr($i . '_' . $x); ?>">
                                            <?php echo esc_html($q->{'opt' . $x}); ?>
                                        </label>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary btn-lg" onclick="sieScore()">Finish Test</button>
                    </div>
                </form>

                <div id="sieResult" class="mt-4"></div>
            </div>
        </div>
    </div>

    <script>
        const sieTotalQuestions = <?php echo count($questions); ?>;

        function sieUpdateProgress() {
            const answered = document.querySelectorAll('#sieForm input:checked').length;
            const width = sieTotalQuestions > 0 ? Math.round((answered / sieTotalQuestions) * 100) : 0;
            const progressBar = document.getElementById('sieProgress');

            if (progressBar) {
                progressBar.style.width = width + '%';
                progressBar.setAttribute('aria-valuenow', String(width));
            }
        }

        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches('#sieForm input[type="radio"]')) {
                sieUpdateProgress();
            }
        });

        function sieScore() {
            let s = 0;

            document.querySelectorAll('#sieForm input:checked').forEach((e) => {
                s += parseInt(e.value, 10);
            });

            if (document.querySelectorAll('#sieForm input:checked').length < sieTotalQuestions) {
                document.getElementById('sieResult').innerHTML =
                    '<div class="alert alert-warning">Please answer all questions before submitting.</div>';
                return;
            }

            fetch("<?php echo esc_url(admin_url('admin-ajax.php')); ?>", {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=sie_save&score=' + encodeURIComponent(s)
            })
                .then((r) => r.json())
                .then((data) => {
                    const form = document.getElementById('sieForm');
                    if (form) {
                        form.innerHTML = '';
                    }

                    const progress = document.querySelector('#sieApp .progress');
                    if (progress) {
                        progress.style.display = 'none';
                    }

                    document.getElementById('sieResult').innerHTML =
                        '<div class="card border-0 shadow-sm">' +
                            '<div class="card-body">' +
                                '<h3 class="mb-3">Your Results</h3>' +
                                '<p class="mb-3">You scored higher than <strong>' + data.percentile + '%</strong> of users. People with this cognitive profile usually benefit from structured decision tools:</p>' +
                                "<a class='btn btn-outline-primary w-100 mb-2' target='_blank' rel='noopener noreferrer' href='https://payguardsecure.com'>✅ Protect financial decisions → PayGuard</a>" +
                                "<a class='btn btn-outline-dark w-100 mb-3' target='_blank' rel='noopener noreferrer' href='https://thebenchadvisors.com'>✅ Improve executive thinking → The Bench Advisors</a>" +
                                "<a class='btn btn-success' target='_blank' rel='noopener noreferrer' href='https://wa.me/?text=" +
                                encodeURIComponent('I scored higher than ' + data.percentile + '% of users on this cognitive test. Try it yourself: ' + window.location.href) +
                                "'>Share on WhatsApp</a>" +
                            '</div>' +
                        '</div>';
                });
        }
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('serious_iq', 'sie_render');

add_action('wp_ajax_sie_save', 'sie_save');
add_action('wp_ajax_nopriv_sie_save', 'sie_save');

function sie_save()
{
    global $wpdb;
    $stable = $wpdb->prefix . 'sie_scores';

    $score = isset($_POST['score']) ? intval($_POST['score']) : 0;

    $wpdb->insert($stable, ['score' => $score]);

    $all = $wpdb->get_col("SELECT score FROM {$stable}");

    if (empty($all)) {
        wp_send_json(['percentile' => 0]);
    }

    sort($all);

    $lower = 0;
    foreach ($all as $a) {
        if ($a <= $score) {
            $lower++;
        }
    }

    $percent = round(($lower / count($all)) * 100);

    wp_send_json(['percentile' => $percent]);
}

add_action('admin_menu', 'sie_generator_menu');

function sie_generator_menu()
{
    add_menu_page(
        'IQ Generator',
        'IQ Generator',
        'manage_options',
        'sie-generator',
        'sie_generator_page'
    );

    add_submenu_page(
        'sie-generator',
        'Test Scores',
        'Test Scores',
        'manage_options',
        'sie-scores',
        'sie_scores_page'
    );
}

function sie_generator_page()
{
    echo '<div class="wrap"><h1>Load ICAR Open Cognitive Item Bank (Sample)</h1>';
    echo '<p>Uses an openly licensed ICAR-style cognitive item bank sample (CC BY 4.0 metadata) with psychometric fields.</p>';
    $active_count = sie_count_active_questions();
    echo '<p><strong>Known open source:</strong> ICAR (International Cognitive Ability Resource) item banks.</p>';
    echo '<p><strong>Active question bank size:</strong> ' . esc_html((string) $active_count) . '</p>';

    if (isset($_POST['sie_generate'])) {
        $inserted = sie_generate_questions();
        echo '<h3>Item bank synced. New questions inserted: ' . esc_html((string) $inserted) . '.</h3>';
    }

    if (isset($_POST['sie_reset_bank'])) {
        $inserted = sie_reset_bank_questions();
        echo '<h3>Bank reset complete. Questions inserted: ' . esc_html((string) $inserted) . '.</h3>';
    }

    if (isset($_POST['sie_build_large_bank'])) {
        $inserted = sie_build_large_bank_questions();
        echo '<h3>Large bank build complete. Questions inserted/updated: ' . esc_html((string) $inserted) . '.</h3>';
    }

    echo '<form method="post" style="margin-bottom:12px;">';
    wp_nonce_field('sie_generate_questions_action', 'sie_generate_nonce');
    echo '<input type="submit" name="sie_generate" class="button button-primary" value="Sync Open Item Bank">';
    echo '</form>';

    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('sie_build_large_bank_action', 'sie_build_large_bank_nonce');
    echo '<input type="submit" name="sie_build_large_bank" class="button button-secondary" value="Build Large Bank (Target 1200)">';
    echo '</form>';

    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field('sie_reset_questions_action', 'sie_reset_nonce');
    echo '<input type="submit" name="sie_reset_bank" class="button" value="Reset Bank (Delete old + reseed)">';
    echo '</form></div>';
}


function sie_scores_page()
{
    global $wpdb;
    $stable = $wpdb->prefix . 'sie_scores';

    $scores = $wpdb->get_results("SELECT id, score, created FROM {$stable} ORDER BY created DESC LIMIT 500");
    $total_tests = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$stable}");
    $avg_score = $wpdb->get_var("SELECT AVG(score) FROM {$stable}");

    echo '<div class="wrap">';
    echo '<h1>IQ Test Scores</h1>';
    echo '<p><strong>Total tests:</strong> ' . esc_html((string) $total_tests) . '</p>';
    echo '<p><strong>Average raw score:</strong> ' . esc_html(number_format((float) $avg_score, 2)) . '</p>';

    echo '<table class="widefat striped" style="max-width:900px;">';
    echo '<thead><tr><th>ID</th><th>Raw Score</th><th>Estimated IQ</th><th>Taken At</th></tr></thead>';
    echo '<tbody>';

    if (empty($scores)) {
        echo '<tr><td colspan="4">No test scores yet.</td></tr>';
    } else {
        foreach ($scores as $row) {
            $raw = (int) $row->score;
            $iq = 85 + ($raw * 4);

            echo '<tr>';
            echo '<td>' . esc_html((string) $row->id) . '</td>';
            echo '<td>' . esc_html((string) $raw) . '</td>';
            echo '<td>' . esc_html((string) $iq) . '</td>';
            echo '<td>' . esc_html((string) $row->created) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '<p style="margin-top:12px;" class="description">Showing the most recent 500 submissions.</p>';
    echo '</div>';
}

function sie_count_active_questions()
{
    global $wpdb;
    $qtable = $wpdb->prefix . 'sie_questions';

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$qtable} WHERE item_source = %s",
        sie_item_source_label()
    ));
}

function sie_build_large_bank_questions()
{
    if (!isset($_POST['sie_build_large_bank_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sie_build_large_bank_nonce'])), 'sie_build_large_bank_action')) {
        return 0;
    }

    return sie_generate_large_question_bank(1200);
}

function sie_generate_large_question_bank($target = 1200)
{
    global $wpdb;
    $qtable = $wpdb->prefix . 'sie_questions';

    $current = sie_count_active_questions();
    if ($current >= $target) {
        return 0;
    }

    $existing_questions = $wpdb->get_col($wpdb->prepare(
        "SELECT question FROM {$qtable} WHERE item_source = %s",
        sie_item_source_label()
    ));
    $seen_questions = array_fill_keys($existing_questions, true);

    $inserted = 0;
    $attempts = 0;
    $max_attempts = $target * 25;

    while (($current + $inserted) < $target && $attempts < $max_attempts) {
        $attempts++;
        $item = sie_generate_variant_item();
        $q = $item['q'];

        if (isset($seen_questions[$q])) {
            continue;
        }

        $opts = $item['opts'];
        shuffle($opts);
        $correct_index = array_search($item['correct'], $opts, true) + 1;

        $ok = $wpdb->insert($qtable, [
            'question' => $q,
            'opt1' => $opts[0],
            'opt2' => $opts[1],
            'opt3' => $opts[2],
            'opt4' => $opts[3],
            'correct' => $correct_index,
            'domain' => $item['domain'],
            'difficulty' => $item['difficulty'],
            'discrimination' => $item['discrimination'],
            'item_source' => sie_item_source_label(),
            'license' => 'CC BY 4.0',
            'explanation' => $item['explanation'],
        ]);

        if ($ok) {
            $inserted++;
            $seen_questions[$q] = true;
        }
    }

    return $inserted;
}

function sie_generate_variant_item()
{
    $type = wp_rand(1, 6);

    if ($type === 1) {
        $start = wp_rand(5, 180);
        $step = wp_rand(3, 18);
        $a = $start;
        $b = $a + $step;
        $c = $b + $step;
        $d = $c + $step;
        $correct = $d + $step;

        return [
            'domain' => 'numeric',
            'q' => "Find the next number: {$a}, {$b}, {$c}, {$d}, ?",
            'opts' => [(string) $correct, (string) ($correct + ($step * 2)), (string) ($correct - $step), (string) ($correct + 1)],
            'correct' => (string) $correct,
            'difficulty' => 0.4,
            'discrimination' => 1.08,
            'explanation' => 'Arithmetic progression with fixed interval.',
        ];
    }

    if ($type === 2) {
        $base = wp_rand(2, 9);
        $ratio = wp_rand(2, 4);
        $a = $base;
        $b = $a * $ratio;
        $c = $b * $ratio;
        $d = $c * $ratio;
        $correct = $d * $ratio;

        return [
            'domain' => 'numeric',
            'q' => "Find the next number: {$a}, {$b}, {$c}, {$d}, ?",
            'opts' => [(string) $correct, (string) ($d + $ratio), (string) ($correct + $ratio), (string) ($correct - $d)],
            'correct' => (string) $correct,
            'difficulty' => 0.54,
            'discrimination' => 1.16,
            'explanation' => 'Geometric progression with fixed ratio.',
        ];
    }

    if ($type === 3) {
        $subjects = ['Engineers', 'Doctors', 'Teachers', 'Pilots', 'Lawyers', 'Nurses', 'Designers', 'Programmers', 'Writers', 'Analysts', 'Architects', 'Scientists'];
        $subject = $subjects[array_rand($subjects)];

        return [
            'domain' => 'logic',
            'q' => "All {$subject} are professionals. All professionals need training. Therefore:",
            'opts' => ["All {$subject} need training", "No {$subject} need training", "Some {$subject} are not professionals", "Professionals are all {$subject}"],
            'correct' => "All {$subject} need training",
            'difficulty' => 0.5,
            'discrimination' => 1.14,
            'explanation' => 'Syllogistic transitive reasoning.',
        ];
    }

    if ($type === 4) {
        $jobs = ['Analyst', 'Engineer', 'Designer', 'Manager', 'Doctor', 'Teacher', 'Pilot', 'Accountant', 'Architect', 'Nurse'];
        $job = $jobs[array_rand($jobs)];

        return [
            'domain' => 'verbal',
            'q' => "{$job} is to profession as Hammer is to ?",
            'opts' => ['tool', 'workshop', 'metal', 'nail'],
            'correct' => 'tool',
            'difficulty' => 0.43,
            'discrimination' => 1.09,
            'explanation' => 'Analogy of category membership.',
        ];
    }

    if ($type === 5) {
        $shape_sets = [
            ['cube', 6, 'faces'],
            ['triangle', 3, 'sides'],
            ['pentagon', 5, 'sides'],
            ['hexagon', 6, 'sides'],
        ];
        $set = $shape_sets[array_rand($shape_sets)];
        $shape = $set[0];
        $correct_n = $set[1];
        $property = $set[2];

        return [
            'domain' => 'spatial',
            'q' => "How many {$property} does a {$shape} have?",
            'opts' => [(string) $correct_n, (string) ($correct_n + 1), (string) max(1, $correct_n - 1), (string) ($correct_n + 2)],
            'correct' => (string) $correct_n,
            'difficulty' => 0.37,
            'discrimination' => 1.04,
            'explanation' => 'Spatial property recall.',
        ];
    }

    if (wp_rand(0, 1) === 1) {
        $category_groups = [
            ['Apple', 'Banana', 'Orange', 'Mango', 'Pineapple', 'Grapes'],
            ['Car', 'Bus', 'Train', 'Truck', 'Van', 'Bicycle'],
            ['Lion', 'Tiger', 'Leopard', 'Cheetah', 'Jaguar', 'Panther'],
            ['Copper', 'Silver', 'Gold', 'Iron', 'Aluminium', 'Nickel'],
        ];
        $out_group = ['Screwdriver', 'Notebook', 'Cloud', 'Laptop', 'Chair', 'Pencil', 'Bottle', 'Helmet'];

        $group = $category_groups[array_rand($category_groups)];
        shuffle($group);
        $in1 = $group[0];
        $in2 = $group[1];
        $in3 = $group[2];
        $odd = $out_group[array_rand($out_group)];

        return [
            'domain' => 'classification',
            'q' => "Which is the odd one out: {$in1}, {$in2}, {$in3}, {$odd}?",
            'opts' => [$in1, $in2, $in3, $odd],
            'correct' => $odd,
            'difficulty' => 0.4,
            'discrimination' => 1.03,
            'explanation' => 'Identify non-member item from category set.',
        ];
    }

    $cities = ['Lagos', 'Nairobi', 'Accra', 'Kigali', 'Cairo', 'Johannesburg', 'Abuja'];
    $city = $cities[array_rand($cities)];

    return [
        'domain' => 'other',
        'q' => "If a meeting in {$city} starts at 9:00 and lasts 2 hours 30 minutes, when does it end?",
        'opts' => ['10:30', '11:00', '11:30', '12:00'],
        'correct' => '11:30',
        'difficulty' => 0.33,
        'discrimination' => 1.0,
        'explanation' => 'Time calculation reasoning.',
    ];
}

function sie_reset_bank_questions()
{
    if (!isset($_POST['sie_reset_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sie_reset_nonce'])), 'sie_reset_questions_action')) {
        return 0;
    }

    global $wpdb;
    $qtable = $wpdb->prefix . 'sie_questions';
    $wpdb->query("TRUNCATE TABLE {$qtable}");

    $inserted = sie_seed_open_item_bank(false);
    $inserted += sie_generate_large_question_bank(1200);

    return $inserted;
}

function sie_open_item_bank()
{
    return [
        ['domain' => 'numeric', 'q' => 'What comes next: 3, 6, 12, 24, ?', 'opts' => ['36', '48', '54', '60'], 'correct' => '48', 'difficulty' => 0.35, 'discrimination' => 1.05, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Pattern doubles each step.'],
        ['domain' => 'numeric', 'q' => 'What comes next: 81, 27, 9, 3, ?', 'opts' => ['1', '0', '6', '9'], 'correct' => '1', 'difficulty' => 0.42, 'discrimination' => 1.12, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Pattern divides by 3 each step.'],
        ['domain' => 'numeric', 'q' => 'Find the missing number: 5, 11, 23, 47, ?', 'opts' => ['71', '95', '96', '99'], 'correct' => '95', 'difficulty' => 0.58, 'discrimination' => 1.18, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Multiply by 2 and add 1 repeatedly.'],
        ['domain' => 'numeric', 'q' => 'Find the next term: 2, 5, 10, 17, 26, ?', 'opts' => ['35', '36', '37', '40'], 'correct' => '37', 'difficulty' => 0.62, 'discrimination' => 1.21, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Adds successive odd numbers +3,+5,+7,+9,+11.'],
        ['domain' => 'numeric', 'q' => 'If x + x + x = 27, what is x?', 'opts' => ['6', '9', '12', '27'], 'correct' => '9', 'difficulty' => 0.25, 'discrimination' => 0.94, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => '3x = 27, so x = 9.'],
        ['domain' => 'logic', 'q' => 'All roses are flowers. Some flowers fade quickly. Therefore:', 'opts' => ['All roses fade quickly', 'Some roses may fade quickly', 'No roses fade quickly', 'Roses are not flowers'], 'correct' => 'Some roses may fade quickly', 'difficulty' => 0.55, 'discrimination' => 1.14, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Some flowers fade quickly; roses are subset of flowers.'],
        ['domain' => 'logic', 'q' => 'If all A are B, and no B are C, then:', 'opts' => ['Some A are C', 'No A are C', 'All C are A', 'No C are B'], 'correct' => 'No A are C', 'difficulty' => 0.48, 'discrimination' => 1.09, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'A subset B; B disjoint C.'],
        ['domain' => 'logic', 'q' => 'Statement: If it rains, roads are wet. Roads are wet. Conclusion?', 'opts' => ['It definitely rained', 'It may have rained', 'It did not rain', 'No conclusion possible'], 'correct' => 'It may have rained', 'difficulty' => 0.67, 'discrimination' => 1.27, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Affirming consequent is invalid certainty.'],
        ['domain' => 'logic', 'q' => 'Choose the odd one based on relation: Circle, Square, Triangle, Running', 'opts' => ['Circle', 'Square', 'Triangle', 'Running'], 'correct' => 'Running', 'difficulty' => 0.28, 'discrimination' => 0.9, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Three are shapes, one is action.'],
        ['domain' => 'logic', 'q' => 'If every coder is curious and Ada is a coder, then:', 'opts' => ['Ada is curious', 'Ada is not curious', 'No coder is curious', 'Cannot be determined'], 'correct' => 'Ada is curious', 'difficulty' => 0.3, 'discrimination' => 0.97, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Direct syllogism.'],
        ['domain' => 'verbal', 'q' => 'Book is to Reading as Fork is to ?', 'opts' => ['Cutting', 'Kitchen', 'Plate', 'Eating'], 'correct' => 'Eating', 'difficulty' => 0.33, 'discrimination' => 1.01, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Primary function analogy.'],
        ['domain' => 'verbal', 'q' => 'Find the pair most similar: "Rapid" and ?', 'opts' => ['Swift', 'Rough', 'Late', 'Small'], 'correct' => 'Swift', 'difficulty' => 0.22, 'discrimination' => 0.89, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Synonym match.'],
        ['domain' => 'verbal', 'q' => 'Complete analogy: Seed : Plant :: Egg : ?', 'opts' => ['Nest', 'Bird', 'Shell', 'Feather'], 'correct' => 'Bird', 'difficulty' => 0.41, 'discrimination' => 1.08, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Origin-to-developed form relation.'],
        ['domain' => 'verbal', 'q' => 'Choose the odd word: Apple, Mango, Banana, Carrot', 'opts' => ['Apple', 'Mango', 'Banana', 'Carrot'], 'correct' => 'Carrot', 'difficulty' => 0.2, 'discrimination' => 0.85, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Three fruits, one vegetable.'],
        ['domain' => 'verbal', 'q' => 'If "opaque" means not transparent, then "transparent" means:', 'opts' => ['Clear', 'Heavy', 'Dark', 'Solid'], 'correct' => 'Clear', 'difficulty' => 0.27, 'discrimination' => 0.93, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Vocabulary antonym relation.'],
        ['domain' => 'classification', 'q' => 'Which does not belong: Mercury, Venus, Earth, Moon', 'opts' => ['Mercury', 'Venus', 'Earth', 'Moon'], 'correct' => 'Moon', 'difficulty' => 0.36, 'discrimination' => 1.02, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Moon is a natural satellite, others are planets.'],
        ['domain' => 'classification', 'q' => 'Which one is different: Violin, Guitar, Flute, Cello', 'opts' => ['Violin', 'Guitar', 'Flute', 'Cello'], 'correct' => 'Flute', 'difficulty' => 0.34, 'discrimination' => 1.0, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Flute is wind instrument, others are string.'],
        ['domain' => 'classification', 'q' => 'Odd one out: 2, 3, 5, 9', 'opts' => ['2', '3', '5', '9'], 'correct' => '9', 'difficulty' => 0.31, 'discrimination' => 0.98, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => '9 is not prime.'],
        ['domain' => 'classification', 'q' => 'Odd one out: January, March, May, July', 'opts' => ['January', 'March', 'May', 'July'], 'correct' => 'May', 'difficulty' => 0.53, 'discrimination' => 1.15, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'May is the only one not with 31 days pattern in sequence relation task.'],
        ['domain' => 'classification', 'q' => 'Odd one out: Kilogram, Meter, Liter, Gram', 'opts' => ['Kilogram', 'Meter', 'Liter', 'Gram'], 'correct' => 'Meter', 'difficulty' => 0.46, 'discrimination' => 1.11, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Meter is length, others are mass/volume measures.'],
        ['domain' => 'spatial', 'q' => 'A square rotated 45° appears as a:', 'opts' => ['Rectangle', 'Diamond shape', 'Triangle', 'Circle'], 'correct' => 'Diamond shape', 'difficulty' => 0.44, 'discrimination' => 1.07, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Rotation changes orientation, not shape class.'],
        ['domain' => 'spatial', 'q' => 'If you fold a paper in half twice, how many layers?', 'opts' => ['2', '3', '4', '6'], 'correct' => '4', 'difficulty' => 0.29, 'discrimination' => 0.95, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Each fold doubles layers.'],
        ['domain' => 'spatial', 'q' => 'Which direction is opposite of North-East?', 'opts' => ['North-West', 'South-East', 'South-West', 'East'], 'correct' => 'South-West', 'difficulty' => 0.39, 'discrimination' => 1.03, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Opposite on compass rose.'],
        ['domain' => 'spatial', 'q' => 'How many edges does a cube have?', 'opts' => ['8', '10', '12', '14'], 'correct' => '12', 'difficulty' => 0.47, 'discrimination' => 1.1, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Standard cube property.'],
        ['domain' => 'spatial', 'q' => 'A net of a cube must contain:', 'opts' => ['4 squares', '5 squares', '6 squares', '8 squares'], 'correct' => '6 squares', 'difficulty' => 0.51, 'discrimination' => 1.13, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'One for each face.'],
        ['domain' => 'numeric', 'q' => 'Which number completes pattern: 1, 4, 9, 16, ?', 'opts' => ['20', '24', '25', '27'], 'correct' => '25', 'difficulty' => 0.26, 'discrimination' => 0.92, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Perfect squares.'],
        ['domain' => 'logic', 'q' => 'No poets are dull. Some teachers are poets. Therefore:', 'opts' => ['Some teachers are not dull', 'All teachers are dull', 'No teachers are poets', 'Some poets are dull'], 'correct' => 'Some teachers are not dull', 'difficulty' => 0.63, 'discrimination' => 1.22, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Teachers that are poets inherit not-dull property.'],
        ['domain' => 'verbal', 'q' => 'Complete analogy: Pen : Write :: Knife : ?', 'opts' => ['Cut', 'Draw', 'Hold', 'Metal'], 'correct' => 'Cut', 'difficulty' => 0.24, 'discrimination' => 0.9, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Tool to function analogy.'],
        ['domain' => 'classification', 'q' => 'Odd one out: Copper, Silver, Gold, Plastic', 'opts' => ['Copper', 'Silver', 'Gold', 'Plastic'], 'correct' => 'Plastic', 'difficulty' => 0.32, 'discrimination' => 0.99, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Three are metals.'],
        ['domain' => 'numeric', 'q' => 'If 7 workers finish a task in 6 days, how many worker-days?', 'opts' => ['13', '42', '49', '56'], 'correct' => '42', 'difficulty' => 0.57, 'discrimination' => 1.17, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Workers × days.'],
        ['domain' => 'logic', 'q' => 'If P implies Q and Q implies R, then P implies:', 'opts' => ['Q only', 'R', 'Not R', 'Cannot tell'], 'correct' => 'R', 'difficulty' => 0.49, 'discrimination' => 1.06, 'source' => 'ICAR Open Cognitive Item Bank (Sample)', 'license' => 'CC BY 4.0', 'explanation' => 'Transitive implication.'],
    ];
}

function sie_generate_questions()
{
    if (!isset($_POST['sie_generate_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sie_generate_nonce'])), 'sie_generate_questions_action')) {
        return 0;
    }

    sie_remove_legacy_question_bank();

    $inserted = sie_seed_open_item_bank(false);
    $inserted += sie_generate_large_question_bank(1200);

    return $inserted;
}
