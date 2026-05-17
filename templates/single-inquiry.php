<?php
/**
 * single-inquiry.php — 본 플러그인의 기본 단일 페이지 템플릿.
 * 테마에 같은 이름 파일이 있으면 그 파일이 우선한다 (cpt.php 의 single_template 필터 참조).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main class="inquiry-single">
<?php
while ( have_posts() ) :
	the_post();
	$post_id  = get_the_ID();
	$is_owner = inquiry_board_is_owner( (int) $post_id );
	$want_edit = isset( $_GET['inquiry_action'] ) && $_GET['inquiry_action'] === 'edit';
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
		<header class="entry-header">
			<h1 class="entry-title"><?php the_title(); ?></h1>
			<p class="entry-meta">
				<span class="author"><?php echo esc_html( (string) get_post_meta( $post_id, '_inquiry_author_name', true ) ?: __( '익명', 'wp-qna-board' ) ); ?></span>
				<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				<?php $views = (int) get_post_meta( $post_id, '_inquiry_view', true ); ?>
				<?php if ( $views ) : ?><span class="views"><?php echo esc_html( sprintf( __( '조회 %d', 'wp-qna-board' ), $views ) ); ?></span><?php endif; ?>
				<?php if ( post_password_required() ) : ?>
					<span class="locked">🔒 <?php esc_html_e( '비밀글', 'wp-qna-board' ); ?></span>
				<?php endif; ?>
			</p>
		</header>

		<div class="entry-content">
			<?php if ( $is_owner && $want_edit ) : ?>
				<?php echo inquiry_board_render_edit_form( (int) $post_id ); ?>
			<?php else : ?>
				<?php the_content(); ?>

				<?php
				$atts = (array) get_post_meta( $post_id, '_inquiry_attachments', true );
				if ( $atts && ! post_password_required() ) :
					?>
					<ul class="inquiry-attachments">
						<?php foreach ( $atts as $att_id ) :
							$url = wp_get_attachment_url( $att_id );
							if ( ! $url ) continue;
							?>
							<li><a href="<?php echo esc_url( $url ); ?>" rel="noopener"><?php echo esc_html( basename( $url ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $is_owner ) : ?>
					<p class="inquiry-owner-actions">
						<a class="button" href="<?php echo esc_url( add_query_arg( 'inquiry_action', 'edit', get_permalink( $post_id ) ) ); ?>"><?php esc_html_e( '수정', 'wp-qna-board' ); ?></a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php comments_template(); ?>
	</article>
<?php endwhile; ?>
</main>
<?php
get_footer();
