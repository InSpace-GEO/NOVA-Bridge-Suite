<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Immutable description of the entity type one storage operation targets (a
 * source post or a source term), threaded through WGTAI_Storage_Service so the
 * class keeps one sanitiser and one set of persistence primitives instead of
 * forking either into a subclass.
 *
 * Why not a subclass: WGTAI_REST_Controller and WGTAI_Render_Service both
 * type-hint WGTAI_Storage_Service, so a WGTAI_Term_Storage_Service subclass
 * would satisfy both constructors. A wiring slip in wgtai_bootstrap() handing
 * the wrong instance to the wrong consumer would then type-check and silently
 * write post payloads into termmeta, or serve term payloads on posts. Passing
 * a descriptor value object instead of a second class means there is no
 * second type for that mistake to type-check against.
 *
 * PHP 7.4 compatibility is deliberate and load-bearing: the suite header
 * declares "Requires PHP: 7.4" (nova-bridge-suite.php), plugin-update-checker
 * publishes that value to every site, and weglot-translation-api.php requires
 * this file unconditionally at plugin load. A parse error here is uncatchable
 * and would fatal the whole bridge suite, not just the Weglot module, on any
 * site below the syntax floor. So: no promoted constructor properties (8.0)
 * and no readonly (8.1). Typed properties are 7.4 and are used. Immutability
 * is enforced by encapsulation instead - every property is private and there
 * is no setter, so a descriptor cannot be mutated after construction.
 */
final class WGTAI_Storage_Entity
{
    /** @var string Payload/result key carrying the entity id ('source_post_id' / 'source_term_id'). */
    private string $id_key;

    /** @var array<string,array{0:string,1:string}> input key => [payload key, sanitizer type: 'text'|'html'|'slug']. */
    private array $field_map;

    /** @var array<int,string> Meta keys this entity accepts as structured (page-builder) documents. */
    private array $structured_meta_keys;

    /** @var \Closure(int):bool */
    private \Closure $exists;

    /** @var \Closure():\WP_Error */
    private \Closure $missing_error;

    /** @var \Closure(int,string):mixed */
    private \Closure $read_meta;

    /** @var \Closure(int,string,mixed):void */
    private \Closure $write_meta;

    /** @var \Closure(int,string):bool */
    private \Closure $delete_meta;

    /** @var \Closure(int,string):string */
    private \Closure $url;

    /**
     * @param array<string,array{0:string,1:string}> $field_map
     * @param array<int,string>                     $structured_meta_keys
     */
    public function __construct(
        string $id_key,
        array $field_map,
        array $structured_meta_keys,
        \Closure $exists,
        \Closure $missing_error,
        \Closure $read_meta,
        \Closure $write_meta,
        \Closure $delete_meta,
        \Closure $url
    ) {
        $this->id_key               = $id_key;
        $this->field_map            = $field_map;
        $this->structured_meta_keys = $structured_meta_keys;
        $this->exists               = $exists;
        $this->missing_error        = $missing_error;
        $this->read_meta            = $read_meta;
        $this->write_meta           = $write_meta;
        $this->delete_meta          = $delete_meta;
        $this->url                  = $url;
    }

    public function id_key(): string
    {
        return $this->id_key;
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public function field_map(): array
    {
        return $this->field_map;
    }

    /**
     * @return array<int,string>
     */
    public function structured_meta_keys(): array
    {
        return $this->structured_meta_keys;
    }

    public function exists(int $id): bool
    {
        return (bool) ($this->exists)($id);
    }

    public function missing_error(): \WP_Error
    {
        return ($this->missing_error)();
    }

    /**
     * @return mixed
     */
    public function read_meta(int $id, string $key)
    {
        return ($this->read_meta)($id, $key);
    }

    /**
     * @param mixed $value
     */
    public function write_meta(int $id, string $key, $value): void
    {
        ($this->write_meta)($id, $key, $value);
    }

    public function delete_meta(int $id, string $key): bool
    {
        return (bool) ($this->delete_meta)($id, $key);
    }

    public function url(int $id, string $internal_code): string
    {
        return (string) ($this->url)($id, $internal_code);
    }
}
