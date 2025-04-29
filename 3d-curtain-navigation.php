<?php
/*
Plugin Name: 3D Curtain Navigation
Plugin URI: https://github.com/stronganchor/3d-curtain-navigation/
Description: Adds a 3D curtain-like page transition effect by animating full-screen sections using GSAP. Scroll is now tied to a master timeline, and a hidden spacer element creates the scrollable area so fast or slow scrolls scrub through panels.
Version: 1.12
Author: Strong Anchor Tech
Author URI: https://stronganchortech.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Rewrite internal nav menu links to hashed anchors
add_filter( 'nav_menu_link_attributes', 'dcn_filter_menu_links', 10, 4 );
function dcn_filter_menu_links( $atts, $item, $args, $depth ) {
    if ( empty( $atts['href'] ) || strpos( $atts['href'], '#' ) === 0 ) {
        return $atts;
    }
    $home = home_url();
    $href = $atts['href'];
    if ( strpos( $href, $home ) === 0 || strpos( $href, '/' ) === 0 ) {
        $url  = ( strpos( $href, '/' ) === 0 ) ? $home . $href : $href;
        $path = parse_url( $url, PHP_URL_PATH );
        $slug = trim( $path, '/' ) ?: 'home';
        $atts['data-dcn-url'] = esc_url( $url );
        $atts['href']         = '#' . sanitize_html_class( $slug );
    }
    return $atts;
}

// Enqueue GSAP, ScrollTrigger + inline CSS & JS
add_action( 'wp_enqueue_scripts', 'dcn_enqueue_assets' );
function dcn_enqueue_assets() {
    // Styles
    wp_register_style( 'dcn-style', false );
    wp_enqueue_style( 'dcn-style' );
    wp_add_inline_style( 'dcn-style', dcn_inline_css() );

    // GSAP core + ScrollTrigger
    wp_enqueue_script( 'dcn-gsap',
        'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js',
        [], '3.12.2', true
    );
    wp_enqueue_script( 'dcn-scrolltrigger',
        'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js',
        ['dcn-gsap'], '3.12.2', true
    );

    // Main script
    wp_register_script( 'dcn-script', false, ['dcn-gsap','dcn-scrolltrigger'], null, true );
    wp_enqueue_script( 'dcn-script' );
    wp_add_inline_script( 'dcn-script', dcn_inline_js() );
}

function dcn_inline_css() {
    return <<<CSS
html, body {
  margin: 0;
  padding: 0;
}
.dcn-wrapper {
  position: relative;
  width: 100%;
  height: 100vh;
  overflow: hidden;
  perspective: 1000px;
  perspective-origin: center center;
}
.dcn-nav {
  position: fixed;
  top: 0;
  width: 100%;
  z-index: 999;
}
.dcn-section {
  position: absolute;
  top: 0; left: 0;
  width: 100%; height: 100%;
  transform-style: preserve-3d;
  transform-origin: center center;
  opacity: 0;
}
.dcn-section.current {
  opacity: 1;
}
.dcn-spacer {
  width: 1px;
  visibility: hidden;
}
CSS;
}

function dcn_inline_js() {
    return <<<'JS'
(function(){
  gsap.registerPlugin(ScrollTrigger);

  document.addEventListener('DOMContentLoaded', function(){
    const wrapper  = document.querySelector('.dcn-wrapper');
    const sections = {};
    const order    = [];

    // collect sections
    document.querySelectorAll('.dcn-section').forEach((sec, i) => {
      const id = sec.id || 'home';
      sections[id] = sec;
      order.push(id);
      if (i === 0) {
        sec.classList.add('current');
        gsap.set(sec, { z:0, autoAlpha:1 });
      }
    });

    // build timeline: each panel transition occupies 1 unit
    const tl = gsap.timeline({
      scrollTrigger: {
        trigger:    wrapper,
        start:      'top top',
        end:        () => '+=' + (window.innerHeight * (order.length - 1)),
        scrub:      0.1,
        snap:       1 / (order.length - 1),
        pin:        true,
        anticipatePin: 1
      }
    });

    // for each next panel, schedule exit + enter at times 0,1,2...
    order.forEach((id, i) => {
      if (i === 0) return;
      const prev = sections[ order[i-1] ];
      const cur  = sections[ id ];

      // 1) exit previous
      tl.to(prev, {
        z:        300,
        opacity:  0,
        duration: 1,
        ease:     'power2.in'
      }, i - 1);

      // 2) prepare current behind camera
      tl.set(cur, {
        z:        -300,
        opacity:  0
      }, i - 1 + 0.01);

      // 3) enter current
      tl.to(cur, {
        z:        0,
        opacity:  1,
        duration: 1,
        ease:     'power2.out',
        onStart:  () => {
          document.querySelectorAll('.dcn-section.current')
            .forEach(el=>el.classList.remove('current'));
          cur.classList.add('current');
        }
      }, i - 1 + 0.01);
    });
  });
})();
JS;
}

// Shortcode to output nav + sections, plus hidden spacer to enable scrolling
add_shortcode('dcn_sections', 'dcn_render_sections');
function dcn_render_sections($atts) {
  $atts = shortcode_atts(['menu'=>'primary'], $atts, 'dcn_sections');
  $locs = get_nav_menu_locations();
  if ( empty($locs[$atts['menu']]) ) return '';

  $menu  = wp_get_nav_menu_object($locs[$atts['menu']]);
  $items = wp_get_nav_menu_items($menu->term_id);

  // filter only page items
  $pages = array_filter($items, function($itm){
    return $itm->object==='page';
  });
  $count = count($pages);
  if ($count === 0) return '';

  // wrapper + nav
  $out  = '<div class="dcn-wrapper">';
  $out .= wp_nav_menu([
    'menu'       => $menu->term_id,
    'container'  => '',
    'echo'       => false,
    'menu_class' => 'dcn-nav',
  ]);

  // sections
  foreach ($pages as $item) {
    $pid  = $item->object_id;
    $slug = sanitize_html_class(get_post_field('post_name',$pid) ?: 'home');

    if (
      class_exists('Elementor\Plugin')
      && \Elementor\Plugin::instance()->db->is_built_with_elementor($pid)
    ) {
      $content = \Elementor\Plugin::instance()
                   ->frontend
                   ->get_builder_content_for_display($pid);
    } else {
      $post    = get_post($pid);
      $content = apply_filters('the_content',$post->post_content);
    }

    $out .= "<div id=\"{$slug}\" class=\"dcn-section\">{$content}</div>\n";
  }

  $out .= '</div>';

  // spacer to create scroll height = (panels - 1) full viewports
  if ($count > 1) {
    $spacer_vh = ($count - 1) * 100;
    $out      .= "<div class=\"dcn-spacer\" style=\"height:{$spacer_vh}vh\"></div>";
  }

  return $out;
}
