"use client";

import { use, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { QuestionBankIcon } from "@/components/icons";
import { QuestionForm } from "@/components/bank/QuestionForm";
import { RubricEditor } from "@/components/bank/RubricEditor";
import { ErrorState } from "@/components/ui/states/ErrorState";
import { RowsSkeleton } from "@/components/ui/states/LoadingSkeleton";
import { userMessage } from "@/lib/errors";
import { bank, type BankQuestion } from "@/lib/bank";
import { counted } from "@/lib/labels";
import { useAuth } from "@/lib/auth-context";
import { can, P } from "@/lib/permissions";

export default function EditBankQuestionPage({ params }: { params: Promise<{ uuid: string }> }) {
  const { uuid } = use(params);
  const router = useRouter();
  const { user } = useAuth();

  const [question, setQuestion] = useState<BankQuestion | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [removing, setRemoving] = useState(false);
  // An unused question is deleted for good on one press, so the press asks.
  const [asking, setAsking] = useState(false);
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
      setAsking(false);
    }
  };

  if (loading) return <RowsSkeleton />;
  if (failed || question === null) return <ErrorState onRetry={() => router.refresh()} />;

  return (
    <div className="space-y-6">
      <PageHeader
        Icon={QuestionBankIcon}
        title="تحرير سؤال"
        description={
          question.usage_count === undefined || question.usage_count === 0
            ? "غير مضمومٍ إلى أيّ اختبار بعد."
            : `مضمومٌ إلى ${counted(question.usage_count, {
                one: "اختبارٍ واحد",
                two: "اختبارَين",
                few: "اختبارات",
                many: "اختباراً",
                other: "اختبار",
              })} —التعديل يسري عليها كلّها، ولا يمسّ درجةَ محاولةٍ سابقة.`
        }
        actions={
          <Button variant="danger" onClick={() => setAsking(true)} disabled={removing}>
            حذف أو تعطيل
          </Button>
        }
      />

      <Modal
        open={asking}
        title="حذف السؤال"
        message="إن لم يجلس عليه أيُّ طالب يُحذف نهائياً ولا يمكن استرجاعه، وإن جلس عليه طلابٌ يُعطَّل فقط ولا يُعرَض في الاختبارات الجديدة."
        confirmLabel="احذف أو عطِّل"
        tone="danger"
        busy={removing}
        onConfirm={() => void remove()}
        onCancel={() => setAsking(false)}
      />

      {error !== "" && <Alert tone="danger" title="تعذّر التنفيذ">{error}</Alert>}
      {outcome !== "" && <Alert tone="info" title="عُطِّل ولم يُحذف">{outcome}</Alert>}

      <QuestionForm question={question} />

      {/* Essays only: a machine-marked question has no one to apply a scheme. */}
      {question.type === "essay" && can(user, P.questionsManage) && <RubricEditor question={question} />}
    </div>
  );
}
