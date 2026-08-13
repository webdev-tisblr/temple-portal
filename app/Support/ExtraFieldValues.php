<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Validation and file handling for admin-defined extra fields.
 *
 * The rule and the upload loop lived inline in DonationWebController and the
 * API DonationController. Sevas and campaigns gained extra fields on
 * 2026-08-13, which would have made four copies of "store any uploaded file
 * under extra_data.{key} to R2" — each free to forget the image constraint.
 * One copy instead.
 */
class ExtraFieldValues
{
    /**
     * Validation for a dynamic extra_data bag.
     *
     * extra_data is a mixed bag: a definition can declare text/number/date
     * inputs AND file inputs, all keyed dynamically, so the keys cannot be
     * enumerated here. The image constraint therefore applies ONLY when the
     * value is an actual uploaded file, leaving scalar values untouched.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'extra_data' => ['nullable', 'array'],
            'extra_data.*' => [
                'nullable',
                function (string $attribute, $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    // FLAT key, deliberately. $attribute is "extra_data.photo",
                    // and Laravel reads a dotted RULE key as a nested path — so
                    // the inherited version looked for nested data inside a
                    // flat-keyed array, found nothing, and passed vacuously.
                    // The image/mimes/size constraints on donation uploads had
                    // therefore never fired since they were written in April;
                    // any file type could be stored to R2 under an image field.
                    // Verified and fixed 2026-08-13.
                    $validator = validator(
                        ['upload' => $value],
                        ['upload' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']],
                    );

                    if ($validator->fails()) {
                        $fail('The uploaded file must be a JPG, PNG, or WEBP image under 4 MB.');
                    }
                },
            ],
        ];
    }

    /**
     * Replace uploaded files in $values with their stored R2 keys.
     *
     * Only keys the definition actually declares as `image` are stored, so a
     * crafted request cannot push arbitrary files onto R2 by inventing a field
     * name. Everything else passes through as the scalar the devotee typed.
     *
     * @param  array<int, array<string, mixed>>|null  $definitions  the model's extra_fields
     * @param  array<string, mixed>|null  $values  the submitted extra_data
     * @return array<string, mixed>|null
     */
    public static function store(Request $request, ?array $definitions, ?array $values, string $directory): ?array
    {
        if (empty($definitions)) {
            return $values;
        }

        foreach ($definitions as $field) {
            $key = $field['key'] ?? null;

            if (! $key || ($field['type'] ?? '') !== 'image') {
                continue;
            }

            if ($request->hasFile("extra_data.{$key}")) {
                $values[$key] = $request->file("extra_data.{$key}")->store($directory, 'r2');
            }
        }

        return $values;
    }
}
