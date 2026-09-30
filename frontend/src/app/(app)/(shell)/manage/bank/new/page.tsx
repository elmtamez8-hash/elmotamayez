import { QuestionForm } from "@/components/bank/QuestionForm";
import { QuestionBankIcon } from "@/components/icons";
import { PageHeader } from "@/components/ui/PageHeader";
import { RequirePermission } from "@/components/ui/states/RefusedState";
import { P } from "@/lib/permissions";

export default function NewBankQuestionPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        Icon={QuestionBankIcon}
        title="سؤال جديد"
        description="الوسوم الأربعة إلزامية — عليها يُبنى تحليل الأخطاء لاحقاً، وبنكٌ يُوسَم بتساهلٍ اليوم ميزةٌ لا يمكن بناؤها غداً."
      />

      {/* `SaveQuestionRequest` asks `questions.manage`. */}
      <RequirePermission permission={P.questionsManage} reason="إضافة الأسئلة إلى البنك واستيرادها يتولّاه المدرّس.">
        <QuestionForm />
      </RequirePermission>
    </div>
  );
}
