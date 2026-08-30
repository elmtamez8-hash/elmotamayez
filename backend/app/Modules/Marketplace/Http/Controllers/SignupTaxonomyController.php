<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Actions\Public\ListSchoolYears;
use App\Modules\Marketplace\Actions\Public\ListSignupTaxonomy;
use Illuminate\Http\JsonResponse;

/**
 * The vocabulary a SIGNUP FORM offers — subjects, broad stages, school years.
 *
 * ⚠️ A SEPARATE CONTROLLER FROM {@see PublicMarketplaceController}, and the
 * separation is the feature. That one answers "what can a visitor filter the
 * marketplace by", which is correctly narrowed to entries with at least one
 * publicly listed teacher. This one answers "what may a person say about
 * themselves while creating an account", which must be the whole vocabulary or
 * the first teacher on a new platform can never apply and the first student can
 * never name their year.
 *
 * ⚠️ NO ENDPOINT HERE TAKES A QUERY PARAMETER. There is nothing to narrow by:
 * the answer is the catalogue. A parameter would be a second cache key
 * dimension, an input to validate, and a way for the two reads to drift apart.
 */
class SignupTaxonomyController extends Controller
{
    public function subjects(ListSignupTaxonomy $action): JsonResponse
    {
        return response()->json($action->handle(ListSignupTaxonomy::SUBJECTS));
    }

    public function gradeLevels(ListSignupTaxonomy $action): JsonResponse
    {
        return response()->json($action->handle(ListSignupTaxonomy::GRADE_LEVELS));
    }

    public function schoolYears(ListSchoolYears $action): JsonResponse
    {
        return response()->json($action->handle());
    }
}
