"use client";

import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { QuestionForm } from "@/components/bank/QuestionForm";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { bank, type BankQuestion } from "@/lib/bank";

export default function EditBankQuestionPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const router = useRouter();

  const [question, setQuestion] = useState<BankQuestion | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [error, setError] = useState("");
  const [outcome, setOutcome] = useState("");

  useEffect(() => {
    setLoading(true);
    setFailed(false);

    bank
      .question(uuid)
      .then((response) => setQuestion(response.data))
      .catch(() => setFailed(true))
      .finally(() => setLoading(false));
  }, [uuid]);

  const remove = async () => {
    setRemoving(true);
    setError("");

    try {
      const { deleted } = await bank.remove(uuid);

      if (deleted) {
        router.push("/manage/bank");
        router.refresh();

        return;
      }

      /*
       | ⚠️ THE ANSWER IS TOLD, NOT ASSUMED. The server disables a question that
       | has been sat and deletes one that has not, and the teacher pressed one
       | button for both. Saying nothing would leave them on a page whose row is
       | still there, reading it as a button that did not work.
       */
      setOutcome(
        "عُطِّل السؤال ولم يُحذف: جلس عليه طلابٌ من قبل، وحذفُه يُتلف أوراقهم المصحَّحة. لن يُعرَض عند بناء اختبارٍ جديد.",
      );
      setQuestion((current) => (current === null ? null : { ...current, is_active: false }));
    } catch (err: unknown) {
      setError(userMessage(err));
    } finally {
      setRemoving(false);
    }
  };

  if (loading) return <RowsSkeleton />;
  if (failed || question === null) return <ErrorState onRetry={() => router.refresh()} />;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">تحرير سؤال</h1>
          <p className="text-sm text-ink-muted">
            {question.usage_count === undefined || question.usage_count === 0
              ? "غير مضمومٍ إلى أيّ اختبار بعد."
              : `مضمومٌ إلى ${question.usage_count} اختبار — التعديل يسري عليها كلّها، ولا يمسّ درجةَ محاولةٍ سابقة.`}
          </p>
        </div>
        <Button variant="danger" onClick={remove} disabled={removing}>
          {removing ? "…" : "حذف أو تعطيل"}
        </Button>
      </header>

      {error !== "" && <Alert tone="danger" title="تعذّر التنفيذ">{error}</Alert>}
      {outcome !== "" && <Alert tone="info" title="عُطِّل ولم يُحذف">{outcome}</Alert>}

      <QuestionForm question={question} />
    </div>
  );
}
