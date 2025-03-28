<?php
/*
Plugin Name: ZacWP Sentiment Analysis
Description: Adds sentiment analysis for wp posts/pages and comments, and ability to filter based on sentiment.
Version: 1.1.1
Author: Zacchaeus Bolaji, Billy Wilcosky
*/
// Exit if accessed directly
defined( 'ABSPATH' ) or exit;

/** Remove any block contents from the string
 * @param $string
 *
 * @return string
 */
function zacwp_sa_clean_string($string) {
    $string = str_split($string);
    $paren_num = 0;
    $new_string = '';
    foreach($string as $char) {
        if ($char == '[') $paren_num++;
        else if ($char == ']') $paren_num--;
        else if ($paren_num == 0) $new_string .= $char;
    }
    return trim($new_string);
}

/** Perform actual sentiment analysis on string
 * @param $string
 *
 * @return mixed
 */
function zacwp_sa_sentiment_analysis($string) {

    require_once (plugin_dir_path(__FILE__).'/inc/autoload.php');
    $sentiment = new \PHPInsight\Sentiment();
    $string = zacwp_sa_clean_string($string);
    // calculations:
    $result['score'] = $sentiment->score($string);
    $result['category'] = $sentiment->categorise($string);
    // output:

    return $result;
}

/** Remove plugin added post/comment meta
 */
function zacwp_sa_run_at_uninstall() {
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}postmeta WHERE `meta_key`=%s OR `meta_key`=%s",
            'zacwp_sa_post_category', 'zacwp_sa_post_score'
        )
    );
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}commentmeta WHERE `meta_key`=%s OR `meta_key`=%s",
            'zacwp_sa_comment_category', 'zacwp_sa_comment_score'
        )
    );
}

/** Add sentiment score column to the admin comments table
 * @param $columns
 *
 * @return mixed
 */
function zacwp_sa_comments_add_sentiment_score_column( $columns )
{
    $columns['zacwp_sa_comment_score'] = __( 'Sentiment Score' );
    return $columns;
}

/** Add sentiment score column to the admin posts/pages table
 * @param $columns
 *
 * @return mixed
 */
function zacwp_sa_posts_add_sentiment_score_column( $columns )
{
    $columns['zacwp_sa_post_score'] = __( 'Sentiment Score' );
    return $columns;
}

/** Runs for each comment row
 * Calculate and save sentiment analysis for those without
 * Display sentiment analysis for current row
 * @param $column
 * @param $comment_ID
 */
function zacwp_sa_comments_add_sentiment_analysis_row( $column, $comment_ID )
{
    if ( 'zacwp_sa_comment_score' === $column ) {
        $score = get_comment_meta( $comment_ID, 'zacwp_sa_comment_score', true );
        if ( empty($score)) {
            $comment = get_comment( $comment_ID, ARRAY_A);
            $meta = zacwp_sa_sentiment_analysis($comment['comment_content']);

            $score = zacwp_sa_set_score($meta['score']);

            update_comment_meta($comment_ID, 'zacwp_sa_comment_score', $score);
            update_comment_meta($comment_ID, 'zacwp_sa_comment_category', $meta['category']);

        }

        echo $score;

    }

}

/** Runs for each post column
 * Calculate and save sentiment analysis for those without
 * Display sentiment analysis for current row
 *
 * @param $column
 * @param $post_ID
 */
function zacwp_sa_posts_add_sentiment_analysis_row( $column, $post_ID )
{
    if ( 'zacwp_sa_post_score' === $column) {
        $score = get_post_meta( $post_ID, 'zacwp_sa_post_score', true );
        if ( empty($score)) {
            $post = get_post( $post_ID, ARRAY_A);
            $meta = zacwp_sa_sentiment_analysis($post['post_content']);
            $score = zacwp_sa_set_score($meta['score']);
            update_post_meta($post_ID, 'zacwp_sa_post_score', $score);
            update_post_meta($post_ID, 'zacwp_sa_post_category', $meta['category']);

        }

        echo $score;

    }
}

/** Return formatted sentiment category with score percent
 * @param $array
 *
 * @return string
 */
function zacwp_sa_set_score($array) {
    if (empty($array)) {
        return "<div style='background-color:grey;color:white;text-align: center;'>UNKNOWN (0%)</div>";
    }

    $max = max($array);
    $percent = round($max * 100, 2);
    $score = array_keys($array, $max)[0];

    $html = '';
    switch ($score) {
        case 'neg':
            $html = "<div style='background-color:red;color:white;text-align: center;'>NEGATIVE ($percent%)</div>";
            break;
        case 'pos':
            $html = "<div style='background-color:green;color:white;text-align: center;'>POSITIVE ($percent%)</div>";
            break;
        case 'neu':
            $html = "<div style='background-color:grey;color:white;text-align: center;'>NEUTRAL ($percent%)</div>";
            break;
        default:
            $html = "<div style='background-color:grey;color:white;text-align: center;'>UNKNOWN ($percent%)</div>";
            break;
    }

    return $html;
}

/** Update specified post sentiment score and category
 * @param $post_ID
 */
function zacwp_sa_update_post_sentiment($post_ID) {
    $post = get_post( $post_ID, ARRAY_A);
    $meta = zacwp_sa_sentiment_analysis($post['post_content']);
    $score = zacwp_sa_set_score($meta['score']);
    update_post_meta($post_ID, 'zacwp_sa_post_score', $score);
    update_post_meta($post_ID, 'zacwp_sa_post_category', $meta['category']);
}

/** Update specified comment sentiment score and category
 *
 * @param $comment_ID
 */
function zacwp_sa_update_comment_sentiment($comment_ID) {
    $comment = get_comment( $comment_ID, ARRAY_A);
    $meta = zacwp_sa_sentiment_analysis($comment['comment_content']);
    $score = zacwp_sa_set_score($meta['score']);
    update_comment_meta($comment_ID, 'zacwp_sa_comment_score', $score);
    update_comment_meta($comment_ID, 'zacwp_sa_comment_category', $meta['category']);
}

/** Add sentiment analysis filter for post
 * @param $query
 */
function zacwp_sa_admin_posts_filter( $query ) {
    global $pagenow;
    if ( is_admin() && $pagenow === 'edit.php' && isset($_GET['post_sentiment_type']) && $_GET['post_sentiment_type'] !== '' ) {
        $meta_query = [
            [
                'key'     => 'zacwp_sa_post_category',
                'value'   => sanitize_text_field($_GET['post_sentiment_type']),
                'compare' => '='
            ]
        ];
        $query->set('meta_query', $meta_query);
    }
}

/** Add sentiment filter form to the posts page
 */
function zacwp_sa_posts_add_sentiment_score_filter_form() {
    $values = [
        'neu' => 'Neutral',
        'pos' => 'Positive',
        'neg' => 'Negative'
    ];
    ?>
    <select name="post_sentiment_type">
        <option value=""><?php _e('Filter By Sentiment', 'commmm'); ?></option>
        <?php
        $current = isset($_GET['post_sentiment_type']) ? sanitize_text_field($_GET['post_sentiment_type']) : '';
        foreach ($values as $key => $value) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($key, $current, false),
                esc_html($value)
            );
        }
        ?>
    </select>
    <?php
}

/**Filter comments based on specified sentiment
 *
 * @param $comments
 *
 * @return mixed
 */
function zacwp_sa_filter_comments($comments){
	global $pagenow;
	if($pagenow == 'edit-comments.php'
	   && isset($_GET['sentiment_type'])
	   && !empty($_GET['sentiment_type'])
	){
		foreach($comments as $i => $comment){

			if(get_comment_meta($comment->comment_ID, 'zacwp_sa_comment_category', true) != sanitize_text_field($_GET['sentiment_type'])) unset($comments[$i]);
		}
	}
	return $comments;
}

/**Add sentiment filter form to the comments page
 *
 * @return mixed
 */
function zacwp_sa_comments_add_sentiment_score_filter_form()
{


	$values = [
		'neu' => 'Neutral',
		'pos' => 'Positive',
		'neg' => 'Negative'
	];
	?>
    <select name="sentiment_type">
        <option value=""><?php _e('Filter By Sentiment', 'commmm'); ?></option>
		<?php
		$current = isset($_GET['sentiment_type'])? $_GET['sentiment_type']:'';
		foreach ($values as $key => $value) {
			printf
			(
				'<option value="%s"%s>%s</option>',
				$key,
				$key == $current ? ' selected="selected"' : '',
				$value
			);

		}
		?>
    </select>
	<?php
}

/** Add sentiment analysis meta to post if not exist
 *
 * @param $post_id
 *
 * @return mixed
 */
function zacwp_sa_analyze_new_edited_post( $post_id ) {

    // If this is a revision, don't send the email.
    if ( wp_is_post_revision( $post_id ) )
        return;

    $score = get_post_meta( $post_id, 'zacwp_sa_post_score', true );
    if ( empty($score)) {
        $post = get_post( $post_id, ARRAY_A);
        $meta = zacwp_sa_sentiment_analysis($post['post_content']);
        $score = zacwp_sa_set_score($meta['score']);
        update_post_meta($post_id, 'zacwp_sa_post_score', $score);
        update_post_meta($post_id, 'zacwp_sa_post_category', $meta['category']);

    }

}

/** Add sentiment analysis meta to comment if not exist
 *
 * @param $comment_id
 * @param $comment_approved
 *
 * @return mixed
 */
function zacwp_sa_analyze_new_edited_comment( $comment_id, $comment_approved ) {

    $score = get_comment_meta( $comment_id, 'zacwp_sa_comment_score', true );
    if ( empty($score)) {
        $comment = get_comment( $comment_id, ARRAY_A);
        $meta = zacwp_sa_sentiment_analysis($comment['comment_content']);
        $score = zacwp_sa_set_score($meta['score']);
        update_comment_meta($comment_id, 'zacwp_sa_comment_score', $score);
        update_comment_meta($comment_id, 'zacwp_sa_comment_category', $meta['category']);

    }

}

//run when plugin is uninstalled
register_uninstall_hook( __FILE__, 'zacwp_sa_run_at_uninstall' );

//add sentiment filters
add_filter('parse_query', 'zacwp_sa_admin_posts_filter');
add_filter('the_comments', 'zacwp_sa_filter_comments');

//add filter form
add_action('restrict_manage_posts', 'zacwp_sa_posts_add_sentiment_score_filter_form');
add_action('restrict_manage_comments', 'zacwp_sa_comments_add_sentiment_score_filter_form');

//perform when comment/post is created/updated
add_action('wp_insert_post', 'zacwp_sa_analyze_new_edited_post', 10, 3);
add_action('comment_post', 'zacwp_sa_analyze_new_edited_comment', 10, 2);

//add sentiment score column to admin tables
add_filter('manage_edit-comments_columns', 'zacwp_sa_comments_add_sentiment_score_column');
add_filter('manage_posts_columns', 'zacwp_sa_posts_add_sentiment_score_column');
add_filter('manage_pages_columns', 'zacwp_sa_posts_add_sentiment_score_column');

//deduce and display sentiment score for each post/comment
add_filter('manage_comments_custom_column', 'zacwp_sa_comments_add_sentiment_analysis_row', 10, 2);
add_filter('manage_posts_custom_column', 'zacwp_sa_posts_add_sentiment_analysis_row', 11, 2);
add_filter('manage_pages_custom_column', 'zacwp_sa_posts_add_sentiment_analysis_row', 12, 2);

//add setting to reset scores

add_action('admin_menu', 'zacwp_sa_add_settings_submenu');

function zacwp_sa_add_settings_submenu() {
    add_options_page(
        'Sentiment Analysis', // Page title
        'Sentiment Analysis',          // Menu title
        'manage_options',              // Capability
        'zacwp_sa_settings',           // Menu slug
        'zacwp_sa_settings_page'       // Function to display the page content
    );
}

function zacwp_sa_settings_page() {
    ?>
    <div class="wrap">
        <h1>Sentiment Analysis Settings</h1>
        <p>Click the button below to update the sentiment scores for all existing comments and posts.</p>
        <button id="zacwp-sa-update-scores" class="button button-primary">Update Sentiment Scores</button>
        <div id="zacwp-sa-update-status"></div>
    </div>
    <?php
}

add_action('wp_ajax_zacwp_sa_update_scores', 'zacwp_sa_update_scores');

function zacwp_sa_update_scores() {
    // Check for nonce security
    check_ajax_referer('zacwp_sa_update_scores_nonce', 'security');

    $batch_size = 10;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'post';

    if ($type === 'post') {
        $args = array(
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => $batch_size,
            'offset' => $offset
        );
        $items = get_posts($args);
    } else {
        $args = array(
            'status' => 'all',
            'number' => $batch_size,
            'offset' => $offset
        );
        $items = get_comments($args);
    }

    foreach ($items as $item) {
        if ($type === 'post') {
            $meta = zacwp_sa_sentiment_analysis($item->post_content);
            $score = zacwp_sa_set_score($meta['score']);
            update_post_meta($item->ID, 'zacwp_sa_post_score', $score);
            update_post_meta($item->ID, 'zacwp_sa_post_category', $meta['category']);
        } else {
            $meta = zacwp_sa_sentiment_analysis($item->comment_content);
            $score = zacwp_sa_set_score($meta['score']);
            update_comment_meta($item->comment_ID, 'zacwp_sa_comment_score', $score);
            update_comment_meta($item->comment_ID, 'zacwp_sa_comment_category', $meta['category']);
        }
    }

    wp_send_json_success(array(
        'type' => $type,
        'offset' => $offset + $batch_size,
        'completed' => count($items) < $batch_size
    ));
}

add_action('admin_footer', 'zacwp_sa_admin_footer_script');

function zacwp_sa_admin_footer_script() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#zacwp-sa-update-scores').on('click', function() {
                var offset = 0;
                var type = 'post';
                var $button = $(this);
                var $status = $('#zacwp-sa-update-status');

                function updateScores() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'zacwp_sa_update_scores',
                            security: '<?php echo wp_create_nonce('zacwp_sa_update_scores_nonce'); ?>',
                            offset: offset,
                            type: type
                        },
                        success: function(response) {
                            if (response.success) {
                                offset = response.data.offset;
                                if (response.data.completed) {
                                    if (type === 'post') {
                                        type = 'comment';
                                        offset = 0;
                                        updateScores();
                                    } else {
                                        $status.html('<p>All scores updated successfully.</p>');
                                    }
                                } else {
                                    $status.html('<p>Updating ' + type + 's... Processed ' + offset + ' so far.</p>');
                                    updateScores();
                                }
                            } else {
                                $status.html('<p>An error occurred. Please try again.</p>');
                            }
                        },
                        error: function() {
                            $status.html('<p>An error occurred. Please try again.</p>');
                        }
                    });
                }

                $button.prop('disabled', true);
                $status.html('<p>Updating scores...</p>');
                updateScores();
            });
        });
    </script>
    <?php
}
?>
