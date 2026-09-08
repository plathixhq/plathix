<?php

declare(strict_types=1);

namespace Plathix\User;

enum AccessLevel: string
{
	case Full = 'full';
	case View = 'view';
	case Upload = 'upload';
	case None = 'none';

	public function canEdit(): bool {
		return $this === self::Full;
	}

	public function canUpload(): bool {
		return match ( $this ) {
			self::Full, self::Upload => true,
			default => false,
		};
	}

	public function satisfies(self $required): bool {
		$rank = [
			self::None->value => 0,
			self::View->value => 1,
			self::Upload->value => 2,
			self::Full->value => 3,
		];

		return ( $rank[ $this->value ] ?? 0 ) >= ( $rank[ $required->value ] ?? 0 );
	}

	public function resolveCap(string $post_type): string {
		if ( '' === $post_type || 'attachment' === $post_type ) {
			return $this === self::View ? 'read' : 'upload_files';
		}

		$obj = get_post_type_object( $post_type );

		return match ( $this ) {
			self::Full => $obj?->cap->publish_posts ?? 'publish_posts',
			default    => $obj?->cap->edit_posts ?? 'edit_posts',
		};
	}
}
