<?php

/**
 * Plugin Name: Cpamatica
 * Description: Custom plugin for importing articles using WP Cron.
 */

class CpamaticaPlugin
{
    public function __construct()
    {
        // Register WP Cron event
        register_activation_hook(__FILE__, [$this, 'schedule_cron']);

        // Import articles using WP Cron
        add_action('cpamatica_import_articles', [$this, 'import_articles']);

        // Shortcode to display article list
        add_shortcode('cpamatica_article_list', [$this, 'cpamatica_article_list_shortcode']);

        // Enqueue styles only when the shortcode is used
        add_action('wp_enqueue_scripts', [$this, 'cpamatica_enqueue_shortcode_styles']);
    }

    // Schedule WP Cron event
    public function schedule_cron(): void
    {
        if (!wp_next_scheduled('cpamatica_import_articles')) {
            wp_schedule_event(time(), 'daily', 'cpamatica_import_articles');
        }
    }

    // Import articles using WP Cron
    public function import_articles(): void
    {
        $api_url = 'https://my.api.mockaroo.com/posts.json';
        $api_key = '413dfbf0';

        $response = wp_remote_get($api_url, [
            'headers' => [
                'X-API-Key' => $api_key
            ]
        ]);

        if (is_wp_error($response)) {
            return;
        }

        $articles = json_decode(wp_remote_retrieve_body($response), true);

        foreach ($articles as $article) {
            $array_of_objects = get_posts([
                'title'     => $article['title'],
                'post_type' => 'any',
            ]);
            if (empty($array_of_objects)) {
                $new_post = [
                    'post_title'   => $article['title'],
                    'post_content' => $article['content'],
                    'post_status'  => 'publish',
                    'post_author'  => $this->get_admin_user(),
                    'post_date'    => $this->get_random_publish_date()
                ];

                $post_id = wp_insert_post($new_post);
                wp_set_object_terms($post_id, $this->get_category($article['category']), 'category');
                if ($post_id && !empty($article['image'])) {
                    $image_url = $article['image'];
                    $image_id  = $this->upload_featured_image($image_url, $post_id);

                    if ($image_id) {
                        set_post_thumbnail($post_id, $image_id);
                    }
                }

                if ($post_id && $article['rating'] !== null) {
                    update_post_meta($post_id, 'rating', $article['rating']);
                }
                if ($post_id && $article['site_link'] !== null) {
                    update_post_meta($post_id, 'site_link', $article['site_link']);
                }
                var_dump($post_id);
            }
        }
    }

    // Get the first user with the 'administrator' role
    private function get_admin_user(): int
    {
        $admin_user = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'number'  => 1
        ]);

        return !empty($admin_user) ? $admin_user[0]->ID : 0;
    }

    // Get or create a category
    private function get_category(string $category_name): int
    {
        $category = get_category_by_slug(sanitize_title($category_name));

        if (!$category) {
            $new_category = wp_insert_category([
                'cat_name' => $category_name,
                'taxonomy' => 'category'
            ]);
            return $new_category;
        }

        return $category->term_id;
    }

    // Upload featured image and return the attachment ID
    private function upload_featured_image(string $image_url, int $post_id): int
    {
        $upload_dir  = wp_upload_dir();
        $image_data  = file_get_contents($image_url);
        $filename    = basename($image_url);
        $file_path   = $upload_dir['path'] . '/' . $filename;
        $file_url    = $upload_dir['url'] . '/' . $filename;

        if (wp_mkdir_p($upload_dir['path'])) {
            file_put_contents($file_path, $image_data);
        } else {
            return false;
        }

        $attachment = [
            'guid'           => $file_url,
            'post_mime_type' => 'image/jpeg',
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        return $attachment_id;
    }

    // Get random publish date between today and 1 month ago
    private function get_random_publish_date(): string
    {
        $today          = strtotime(date('Y-m-d'));
        $one_month_ago  = strtotime('-1 month', $today);
        $random_date    = mt_rand($one_month_ago, $today);

        return date('Y-m-d H:i:s', $random_date);
    }

    // Shortcode to display article list
    public function cpamatica_article_list_shortcode($atts)
    {

        $ids = implode(',', get_posts([
            'fields'          => 'ids', // Only get post IDs
            'posts_per_page'  => 5
        ]));

        $atts = shortcode_atts([
            'title' => 'Latest Articles',
            'count' => 5,
            'sort'  => 'date',
            'ids'   => $ids
        ], $atts);

        $title = $atts['title'];
        $count = $atts['count'];
        $sort  = $atts['sort'];
        $ids   = explode(',', $atts['ids']);

        $query_args = [
            'post_type'      => 'post',
            'posts_per_page' => $count,
            'orderby'        => $sort,
            'post__in'       => $ids,
            'post_status'    => 'publish'
        ];

        $articles = new WP_Query($query_args);

        ob_start();
?>

        <div class="cpamatica-articles">
            <h2><?php echo esc_html($title); ?></h2>
            <ul>
                <?php while ($articles->have_posts()) : $articles->the_post(); ?>
                    <li class="article">
                        <div class="article-thumbnail">
                            <?php if (has_post_thumbnail()) : ?>
                                <?php the_post_thumbnail('thumbnail'); ?>
                            <?php else : ?>
                                <img src="<?php echo esc_url(get_template_directory_uri() . '/placeholder.jpg'); ?>" alt="Placeholder">
                            <?php endif; ?>
                        </div>
                        <div class="article-content">
                            <?php the_category(); ?>
                            <h3 class="article-title"><?php the_title(); ?></h3>
                            <div class="article-content-footer">
                                <a class="read-more-link" href="<?php the_permalink(); ?>">Read More</a>

                                <?php
                                $rating    = get_post_meta(get_the_ID(), 'rating', true);
                                $site_link = get_post_meta(get_the_ID(), 'site_link', true);
                                if (!empty($rating) && !empty($site_link)) {
                                    echo '<div>';
                                }
                                if (!empty($rating)) {
                                    echo '<div class="article-rating">⭐ ' . esc_html($rating) . '</div>';
                                }

                                if (!empty($site_link)) {
                                    echo '<a class="article-site-link" href="' . esc_url($site_link) . '" target="_blank" rel="nofollow">Visit Site</a>';
                                }
                                if (!empty($rating) && !empty($site_link)) {
                                    echo '</div>';
                                }
                                ?>
                            </div>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        </div>

<?php
        wp_reset_postdata();
        return ob_get_clean();
    }

    // Enqueue styles only when the shortcode is used
    public function cpamatica_enqueue_shortcode_styles()
    {
        global $post;

        if (has_shortcode($post->post_content, 'cpamatica_article_list')) {
            wp_enqueue_style('cpamatica-shortcode-styles', plugin_dir_url(__FILE__) . 'shortcode-styles.css');
        }
    }
}

// Instantiate the plugin class
new CpamaticaPlugin();
