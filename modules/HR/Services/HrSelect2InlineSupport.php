<?php

namespace Modules\HR\Services;

final class HrSelect2InlineSupport
{
    /**
     * Quick-create is limited to definitions where every field marked `required`
     * in rules has a `default` in the field definition (so name + notes + status is enough).
     */
    public static function foundationSupportsQuickCreate(HrFoundationDefinition $definition): bool
    {
        foreach ($definition->fields as $field) {
            $rules = $field['rules'] ?? [];

            if (! in_array('required', $rules, true)) {
                continue;
            }

            if (array_key_exists('default', $field)) {
                continue;
            }

            return false;
        }

        return true;
    }
}
