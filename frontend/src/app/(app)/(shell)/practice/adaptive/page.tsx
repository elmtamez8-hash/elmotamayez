"use client";

import Link from "next/link";
import { useEffect, useState } from "react";

import { AdaptiveRunner } from "@/components/practice/AdaptiveRunner";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/states/EmptyState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { adaptive, type AdaptiveConcept, type AdaptiveStart } from "@/lib/adaptive";
import { ApiError } from "@/lib/api";
import { userMessage } from "@/lib/errors";
import { difficultyLabel } from "@/lib/labels";

/**
 * The adaptive path: pick a concept, and the questions follow the student.
 *
 * ⚠️ THE LIST COMES FROM THE SERVER AND IS NOT FILTERED AGAIN HERE. It is derived
 * from the same pool a session draws from and already has the feature switch
 * applied per teacher — a second predicate in the browser is how a screen starts
 * offering a concept the API refuses, and hiding one it would have allowed.
 *
 * ⚠️ AND AN EMPTY LIST IS A SENTENCE, NOT AN ERROR. A student none of whose
 * teachers has switched this on is not doing anything wrong.
 */
export default function AdaptivePracticePage() {
  const [concepts, setConcepts] = useState<AdaptiveConcept[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [starting, setStarting] = useState("");
  const [session, setSession] = useState<AdaptiveStart | null>(null);

  useEffect(() => {
    setLoading(true);

    adaptive
      .concepts()
      .then((response) => setConcepts(response.data))
      // ⚠️ A SENTENCE, NEVER A BLANK PAGE. `.catch(() => undefined)` on a fetch
      // renders an empty screen with the reason sitting unread in the response —
      // the rule against showing raw errors is not a rule for showing nothing.
      .catch((cause: unknown) => setError(userMessage(cause)))
      .finally(() => setLoading(false));
  }, []);

  const begin = async (concept: AdaptiveConcept) => {
    setStarting(concept.uuid);
    setError("");

    try {
      setSession((await adaptive.start(concept.uuid, concept.teacher.uuid)).data);
    } catch (cause: unknown) {
      /*
        ⚠️ 409 IS NOT A FAILURE HERE — IT IS THE SESSION THEY ALREADY HAVE. The
        server answers the conflict WITH the running session and its current
        question precisely so a student who reloaded mid-session is put back into
        it rather than told they may not start and left with nowhere to go.
      */
      if (cause instanceof ApiError && cause.status === 409) {
        const body = cause.body as { data?: AdaptiveStart } | null;

        if (body?.data !== undefined) {
          setSession(body.data);
          setStarting("");

          return;
        }
      }

      setError(userMessage(cause));
    } finally {
      setStarting("");
    }
  };

  if (session !== null) {
    return (
      <div className="space-y-6">
        <h1 className="text-2xl font-bold text-ink">تدريب تكيّفي</h1>
        <AdaptiveRunner
          start={{ session: session.session, question: session.question }}
          onFinish={() => setSession(null)}
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-ink">تدريب تكيّفي</h1>
        <p className="mt-2 text-ink-muted">
          اختر فكرةً، وسنغيّر صعوبةَ السؤال التالي بحسب إجاباتك حتى تُتقنها. لا تُحتسب هذه
          الجلسات في درجاتك.
        </p>
        <Link href="/practice" className="mt-2 inline-block text-sm text-primary-ink underline">
          أو ابْنِ ورقةَ تدريبٍ كاملة
        </Link>
      </div>

      {error !== "" && <Alert tone="danger" title={error} />}

      {loading && <RowsSkeleton />}

      {!loading && concepts !== null && concepts.length === 0 && (
        <EmptyState
          title="لا أفكار متاحة للتدريب التكيّفي بعد"
          description="لم يُفعِّل مدرّسوك هذه الميزة بعد، أو لا أسئلة في بنكهم تخصّك الآن. جرّب «درّب نفسك» في الأثناء."
        />
      )}

      {!loading && concepts !== null && concepts.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2">
          {concepts.map((concept) => (
            <Card key={`${concept.teacher.uuid}:${concept.uuid}`} as="article">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h2 className="font-bold text-ink">{concept.name}</h2>
                  <p className="mt-1 text-sm text-ink-muted">{concept.teacher.name}</p>
                </div>
                {concept.mastered_at !== null && <Badge tone="success">أُتقِنت</Badge>}
              </div>

              <p className="mt-3 text-sm text-ink-muted">
                <bdi>{concept.question_count}</bdi> سؤالاً متاحاً · أعلى مستوًى:{" "}
                {/* Stated, because mastery is measured AT it — a screen that
                    assumed «صعب» would set a bar this concept may not have. */}
                {difficultyLabel(concept.ceiling_difficulty)}
              </p>

              <div className="mt-4">
                <Button onClick={() => begin(concept)} disabled={starting !== ""}>
                  {concept.mastered_at !== null ? "تدرَّب مرّةً أخرى" : "ابدأ"}
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
