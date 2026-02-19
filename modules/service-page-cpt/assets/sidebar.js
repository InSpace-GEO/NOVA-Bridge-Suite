( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { TextControl, TextareaControl, Button } = wp.components;
	const { MediaUpload } = wp.blockEditor || wp.editor;
	const { __ } = wp.i18n;
	const { useSelect, useDispatch } = wp.data;
	const { Fragment } = wp.element;
	const sidebarSettings = window.serviceCptSidebar || {};
	const componentVisibility = sidebarSettings.components || {};
	const showHeroSection = componentVisibility.hero !== false;
	const showIntroSection = componentVisibility.intro !== false;
	const showContentSection = componentVisibility.content !== false;
	const showTableSection = componentVisibility.table !== false;
	const showImagesSection = componentVisibility.images !== false;
	const showSidebarCtaSection = componentVisibility.sidebarCta !== false;
	const showWideCtaSection = componentVisibility.wideCta !== false;
	const showFaqSection = componentVisibility.faq !== false;
	const showHeroCtaFields = sidebarSettings.showHeroCtaFields !== false;
	const showSidebarCtaFields = sidebarSettings.showSidebarCtaFields !== false;
	const showWideCtaFields = sidebarSettings.showWideCtaFields !== false;
	const showGlobalCtaNotice =
		( showHeroSection && ! showHeroCtaFields )
		|| ( showSidebarCtaSection && ! showSidebarCtaFields )
		|| ( showWideCtaSection && ! showWideCtaFields );

	const bulletSlots = [ 0, 1, 2 ];
	const faqSlots = [ 0, 1, 2, 3 ];
	const formatTable = ( table ) => {
		if ( ! Array.isArray( table ) ) {
			return '';
		}

		return table
			.map( ( row ) => ( Array.isArray( row ) ? row.join( ' | ' ) : '' ) )
			.join( '\n' );
	};

	const parseTable = ( value ) => {
		if ( ! value ) {
			return [];
		}

		return value
			.split( /\r?\n/ )
			.map( ( row ) => row.trim() )
			.filter( ( row ) => row.length )
			.map( ( row ) =>
				row.split( '|' ).map( ( cell ) => cell.trim() )
			);
	};

	const SidebarFields = () => {
		const postType = useSelect( ( select ) => {
			const getter = select( 'core/editor' ).getCurrentPostType;
			return getter ? getter() : '';
		}, [] );

		const meta = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
			[]
		);

		const { editPost } = useDispatch( 'core/editor' );

		if ( postType !== 'service_page' ) {
			return null;
		}

		const updateMeta = ( key, value ) => {
			editPost( { meta: { ...meta, [ key ]: value } } );
		};

		const updateBullet = ( index, value ) => {
			const next = Array.isArray( meta.sp_cta_bullets ) ? [ ...meta.sp_cta_bullets ] : [];
			next[ index ] = value;
			updateMeta( 'sp_cta_bullets', next );
		};

		const updateFaq = ( index, field, value ) => {
			const next = Array.isArray( meta.sp_faq )
				? meta.sp_faq.map( ( item ) => ( { ...item } ) )
				: [];

			next[ index ] = {
				...( next[ index ] || { question: '', answer: '' } ),
				[ field ]: value,
			};

			updateMeta( 'sp_faq', next );
		};

		return (
			<PluginDocumentSettingPanel
				name="service-cpt-fields"
				title={ __( 'Service Page Fields', 'service-cpt' ) }
				className="service-cpt-settings-panel"
			>
				{ showImagesSection && (
					<div style={ { display: 'flex', gap: '8px', marginBottom: '12px' } }>
						<MediaUpload
							onSelect={ ( media ) => updateMeta( 'sp_image_1', media?.id || 0 ) }
							allowedTypes={ [ 'image' ] }
							value={ meta.sp_image_1 || 0 }
							render={ ( { open } ) => (
								<Button variant="secondary" onClick={ open }>
									{ meta.sp_image_1 ? __( 'Change Image 1', 'service-cpt' ) : __( 'Select Image 1', 'service-cpt' ) }
								</Button>
							) }
						/>
						<MediaUpload
							onSelect={ ( media ) => updateMeta( 'sp_image_2', media?.id || 0 ) }
							allowedTypes={ [ 'image' ] }
							value={ meta.sp_image_2 || 0 }
							render={ ( { open } ) => (
								<Button variant="secondary" onClick={ open }>
									{ meta.sp_image_2 ? __( 'Change Image 2', 'service-cpt' ) : __( 'Select Image 2', 'service-cpt' ) }
								</Button>
							) }
						/>
					</div>
				) }

				{ showHeroSection && (
					<Fragment>
						<TextControl
							label={ __( 'Hero eyebrow', 'service-cpt' ) }
							value={ meta.sp_hero_eyebrow || '' }
							onChange={ ( value ) => updateMeta( 'sp_hero_eyebrow', value ) }
						/>
						<TextControl
							label={ __( 'Hero title (H1)', 'service-cpt' ) }
							value={ meta.sp_hero_title || '' }
							onChange={ ( value ) => updateMeta( 'sp_hero_title', value ) }
						/>
						<TextareaControl
							label={ __( 'Hero copy', 'service-cpt' ) }
							value={ meta.sp_hero_copy || '' }
							onChange={ ( value ) => updateMeta( 'sp_hero_copy', value ) }
						/>
						{ showHeroCtaFields && (
							<Fragment>
								<TextControl
									label={ __( 'Hero primary label', 'service-cpt' ) }
									value={ meta.sp_hero_primary_label || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_primary_label', value ) }
								/>
								<TextControl
									label={ __( 'Hero primary URL', 'service-cpt' ) }
									value={ meta.sp_hero_primary_url || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_primary_url', value ) }
								/>
								<TextControl
									label={ __( 'Hero secondary label', 'service-cpt' ) }
									value={ meta.sp_hero_secondary_label || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_secondary_label', value ) }
								/>
								<TextControl
									label={ __( 'Hero secondary URL', 'service-cpt' ) }
									value={ meta.sp_hero_secondary_url || '' }
									onChange={ ( value ) => updateMeta( 'sp_hero_secondary_url', value ) }
								/>
							</Fragment>
						) }
					</Fragment>
				) }

				{ ( showIntroSection || showContentSection || showTableSection ) && <hr /> }
				{ showIntroSection && (
					<TextareaControl
						label={ __( 'Intro paragraph', 'service-cpt' ) }
						value={ meta.sp_intro || '' }
						onChange={ ( value ) => updateMeta( 'sp_intro', value ) }
					/>
				) }
				{ showContentSection && (
					<Fragment>
						<TextareaControl
							label={ __( 'Main text 1', 'service-cpt' ) }
							value={ meta.sp_main_1 || '' }
							onChange={ ( value ) => updateMeta( 'sp_main_1', value ) }
						/>
						<TextareaControl
							label={ __( 'Main text 2', 'service-cpt' ) }
							value={ meta.sp_main_2 || '' }
							onChange={ ( value ) => updateMeta( 'sp_main_2', value ) }
						/>
						<TextareaControl
							label={ __( 'Main text 3', 'service-cpt' ) }
							value={ meta.sp_main_3 || '' }
							onChange={ ( value ) => updateMeta( 'sp_main_3', value ) }
						/>
					</Fragment>
				) }
				{ showTableSection && (
					<TextareaControl
						label={ __( 'Table rows (use | between columns)', 'service-cpt' ) }
						value={ formatTable( meta.sp_table ) }
						onChange={ ( value ) => updateMeta( 'sp_table', parseTable( value ) ) }
					/>
				) }

				{ showGlobalCtaNotice && (
					<p style={ { fontSize: '12px', color: '#6b7280', marginTop: '12px' } }>
						{ __( 'Global CTAs are set in Settings → Service Pages. Per-page CTA fields are hidden.', 'service-cpt' ) }
					</p>
				) }

				{ showSidebarCtaSection && showSidebarCtaFields && (
					<Fragment>
						<hr />
						<TextControl
							label={ __( 'Sidebar CTA title (H3)', 'service-cpt' ) }
							value={ meta.sp_sidebar_title || '' }
							onChange={ ( value ) => updateMeta( 'sp_sidebar_title', value ) }
						/>
						<TextareaControl
							label={ __( 'Sidebar CTA copy', 'service-cpt' ) }
							value={ meta.sp_sidebar_copy || '' }
							onChange={ ( value ) => updateMeta( 'sp_sidebar_copy', value ) }
						/>
						<TextControl
							label={ __( 'Sidebar primary label', 'service-cpt' ) }
							value={ meta.sp_sidebar_primary_label || '' }
							onChange={ ( value ) => updateMeta( 'sp_sidebar_primary_label', value ) }
						/>
						<TextControl
							label={ __( 'Sidebar primary URL', 'service-cpt' ) }
							value={ meta.sp_sidebar_primary_url || '' }
							onChange={ ( value ) => updateMeta( 'sp_sidebar_primary_url', value ) }
						/>
						<TextControl
							label={ __( 'Sidebar secondary label', 'service-cpt' ) }
							value={ meta.sp_sidebar_secondary_label || '' }
							onChange={ ( value ) => updateMeta( 'sp_sidebar_secondary_label', value ) }
						/>
						<TextControl
							label={ __( 'Sidebar secondary URL', 'service-cpt' ) }
							value={ meta.sp_sidebar_secondary_url || '' }
							onChange={ ( value ) => updateMeta( 'sp_sidebar_secondary_url', value ) }
						/>
					</Fragment>
				) }

				{ showWideCtaSection && showWideCtaFields && (
					<Fragment>
						<hr />
						<TextControl
							label={ __( 'Wide CTA title (H2)', 'service-cpt' ) }
							value={ meta.sp_cta_title || '' }
							onChange={ ( value ) => updateMeta( 'sp_cta_title', value ) }
						/>
						{ bulletSlots.map( ( index ) => (
							<TextControl
								key={ index }
								label={ sprintf( __( 'Bullet %d', 'service-cpt' ), index + 1 ) }
								value={ ( meta.sp_cta_bullets || [] )[ index ] || '' }
								onChange={ ( value ) => updateBullet( index, value ) }
							/>
						) ) }
						<TextControl
							label={ __( 'CTA button label', 'service-cpt' ) }
							value={ meta.sp_cta_button_label || '' }
							onChange={ ( value ) => updateMeta( 'sp_cta_button_label', value ) }
						/>
						<TextControl
							label={ __( 'CTA button URL', 'service-cpt' ) }
							value={ meta.sp_cta_button_url || '' }
							onChange={ ( value ) => updateMeta( 'sp_cta_button_url', value ) }
						/>
						<TextControl
							label={ __( 'CTA more text', 'service-cpt' ) }
							value={ meta.sp_cta_more_text || '' }
							onChange={ ( value ) => updateMeta( 'sp_cta_more_text', value ) }
						/>
						<TextControl
							label={ __( 'CTA more URL', 'service-cpt' ) }
							value={ meta.sp_cta_more_url || '' }
							onChange={ ( value ) => updateMeta( 'sp_cta_more_url', value ) }
						/>
					</Fragment>
				) }

				{ showFaqSection && (
					<Fragment>
						<hr />
						{ faqSlots.map( ( index ) => (
							<Fragment key={ index }>
								<TextControl
									label={ sprintf( __( 'FAQ question %d', 'service-cpt' ), index + 1 ) }
									value={ ( meta.sp_faq?.[ index ]?.question ) || '' }
									onChange={ ( value ) => updateFaq( index, 'question', value ) }
								/>
								<TextareaControl
									label={ sprintf( __( 'FAQ answer %d', 'service-cpt' ), index + 1 ) }
									value={ ( meta.sp_faq?.[ index ]?.answer ) || '' }
									onChange={ ( value ) => updateFaq( index, 'answer', value ) }
								/>
							</Fragment>
						) ) }
					</Fragment>
				) }

				{ showImagesSection && (
					<p style={ { fontSize: '12px', color: '#6b7280' } }>
						{ __( 'Images are selected via the Featured Image panel or media IDs (sp_image_1 / sp_image_2) via the API.', 'service-cpt' ) }
					</p>
				) }
			</PluginDocumentSettingPanel>
		);
	};

	registerPlugin( 'service-cpt-sidebar', { render: SidebarFields } );
}( window.wp ) );
