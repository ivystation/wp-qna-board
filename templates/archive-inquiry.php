<?php
/**
 * archive-inquiry.php — 기본 아카이브 템플릿.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>
<main class="inquiry-archive">
	<header class="archive-header">
		<h1 class="archive-title"><?php esc_html_e( '문의 게시판', 'wp-qna-board' ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<table class="inquiry-list">
			<thead>
				<tr>
					<th><?php esc_html_e( '카테고리', 'wp-qna-board' ); ?></th>
					<th><?php esc_html_e( '제목', 'wp-qna-board' ); ?></th>
					<th><?php esc_html_e( '작성자', 'wp-qna-board' ); ?></th>
					<th><?php esc_html_e( '작성일', 'wp-qna-board' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php while ( have_posts() ) : the_post(); ?>
				<tr>
					<td>
						<?php
						$terms = get_the_terms( get_the_ID(), 'inquiry_category' );
						echo $terms && ! is_wp_error( $terms ) ? esc_html( $terms[0]->name ) : '';
						?>
					</td>
					<td>
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						<?php if ( post_password_required() ) : ?> 🔒<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) get_post_meta( get_the_ID(), '_inquiry_author_name', true ) ?: __( '익명', 'wp-qna-board' ) ); ?></td>
					<td><?php echo esc_html( get_the_date() ); ?></td>
				</tr>
			<?php endwhile; ?>
			</tbody>
		</table>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<p><?php esc_html_e( '아직 등록된 문의가 없습니다.', 'wp-qna-board' ); ?></p>
	<?php endif; ?>
</main>
<?php get_footer(); ?>
