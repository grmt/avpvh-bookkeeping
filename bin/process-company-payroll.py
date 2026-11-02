#!/usr/bin/env python3
"""Read salary mail without altering it; import validated PDFs and send scheduled reminders."""
import argparse,base64,calendar,datetime as dt,fcntl,hashlib,imaplib,io,json,os,re,ssl,subprocess,sys
from decimal import Decimal
from email import policy
from email.parser import BytesParser
from email.utils import getaddresses
from pathlib import Path
from zoneinfo import ZoneInfo

MAX_BYTES=3*1024*1024

def cents(value):
    if not re.fullmatch(r'\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}',value):raise ValueError('Unsupported payroll amount')
    result=int(Decimal(value.replace('.','').replace(',','.'))*100)
    if result<=0:raise ValueError('Payroll amount must be positive')
    return result

def normalized(value):return re.sub(r'[^a-z0-9]','',value.lower())

def due(now,settings,state):
    return now.day>=settings['day'] and now.hour>=settings['hour'] and state.get('last_attempt_date')!=str(now.date()) and state.get('completed_month')!=now.strftime('%Y-%m')

def parse_document(text,config):
    if normalized(config['employer_name']) not in normalized(text):raise ValueError('Payroll employer mismatch')
    if 'Betalingskenmerk' in text and 'Begindatum tijdvak' in text:
        if config['employer_tax_id'] not in re.sub(r'\s+','',text):raise ValueError('Payroll tax identity mismatch')
        start=re.search(r'Begindatum tijdvak\s*:\s*(\d{2}-\d{2}-\d{4})',text)
        amount=re.search(r'bedraagt:\s*EUR\s*(\d+(?:\.\d{3})*,\d{2})',text)
        due=re.search(r'uiterlijk op\s*(\d{2}-\d{2}-\d{4})',text)
        reference=re.search(r'Betalingskenmerk\s*:\s*([\d ]+)',text)
        iban=re.search(r'IBAN Belastingdienst\s*:\s*([A-Z]{2}\d{2}[A-Z0-9 ]+)',text)
        if not all([start,amount,due,reference,iban]):raise ValueError('Incomplete wage-tax statement')
        first=dt.datetime.strptime(start[1],'%d-%m-%Y').date();deadline=dt.datetime.strptime(due[1],'%d-%m-%Y').date()
        ref=re.sub(r'\s+','',reference[1]);bank=re.sub(r'\s+','',iban[1])
        approved=bank in config['approved_tax_ibans'] or (str(first)<config.get('tax_account_change_date','0000-00-00') and bank in config.get('legacy_tax_ibans',[]))
        if first.day!=1 or deadline<first or not re.fullmatch(r'\d{16}',ref) or not approved:raise ValueError('Wage-tax payment details require review')
        return {'kind':'payroll_tax','period':first.strftime('%Y-%m'),'amount_cents':cents(amount[1]),'reference':ref,'iban':bank,'due_date':str(deadline),'name':'Belastingdienst'}
    start=re.search(r'Begindatum\s+(\d{2}-\d{2}-\d{4})',text)
    amount=re.search(r'Netto loon\s+(\d+(?:\.\d{3})*,\d{2})',text)
    employee=re.search(r'Werknemer\s+(\d+)\s+Periodenummer',text)
    if not all([start,amount,employee]) or employee[1]!=str(config['employee_id']):raise ValueError('Unsupported payslip or employee')
    first=dt.datetime.strptime(start[1],'%d-%m-%Y').date()
    if first.day!=1:raise ValueError('Unsupported payslip period')
    return {'kind':'payslip','period':first.strftime('%Y-%m'),'amount_cents':cents(amount[1])}

def extract(data):
    from pypdf import PdfReader
    reader=PdfReader(io.BytesIO(data),strict=True)
    if reader.is_encrypted and not reader.decrypt(''):raise ValueError('Payroll PDF needs a password')
    if len(reader.pages)>10:raise ValueError('Oversized payroll PDF')
    return '\n'.join(page.extract_text(extraction_mode='layout') or '' for page in reader.pages)

WORKER=r'''
global $wpdb;
$p=json_decode(file_get_contents('php://stdin'),true,512,JSON_THROW_ON_ERROR);wp_set_current_user((int)$p['user_id']);$id=(int)$p['company_id'];$company=AVBK_Companies::company($id,'access');
if($p['operation']==='context'){$all=[];foreach([(int)gmdate('Y')-1,(int)gmdate('Y')] as $year)$all+=AVBK_Company_Payroll::payments($id,$year);ksort($all);$salary=null;$held=false;foreach($all as $period=>$payments)foreach($payments as $payment)if($payment['kind']==='payslip'){$salary=$payment;if($payment['state']==='review')$held=true;}echo wp_json_encode(['settings'=>array_merge(AVBK_Company_Payroll_Automation::settings($id),AVBK_Company_Payroll::enabled($id)?[]:['enabled'=>false]),'salary'=>$salary,'salary_held'=>$held,'baseline_period'=>$all?max(array_keys($all)):null]);return;}
if($p['operation']==='import'){$ids=[];$details=[];foreach($p['documents'] as $d){$doc_id=AVBK_Company_Documents::import_mail($id,$d['source'],$d['filename'],$d['encoded'],['kind'=>$d['kind'],'period'=>$d['period']]);$ids[$d['period']][$d['kind']]=$doc_id;$details[$d['period']][$d['kind']]=$d;}$prepared=0;foreach($details as $period=>$types){if($p['baseline_period']&&$period<$p['baseline_period'])continue;if(count($types)!==2)continue;$existing=AVBK_Company_Payroll::payments($id,(int)substr($period,0,4))[$period]??null;if($existing){$changed=false;foreach($existing as $item)if(($ids[$period][$item['kind']]??0)!==$item['document_id'])$changed=true;if($changed){foreach($existing as &$item){$item['state']='review';$item['note']='Nieuwe of gecorrigeerde bron voor dit loontijdvak: eerst afstemmen.';}unset($item);AVBK_Company_Payroll::save_payments($id,$period,$existing);}continue;}$tax=$types['payroll_tax'];$salary=$types['payslip'];$entries=[['kind'=>'payroll_tax','document_id'=>$ids[$period]['payroll_tax'],'iban'=>$tax['iban'],'name'=>$tax['name'],'reference'=>$tax['reference'],'amount_cents'=>$tax['amount_cents'],'due_date'=>$tax['due_date'],'state'=>'pending','note'=>'Automatisch overgenomen uit de gecontroleerde belastingopgave; betaling nog te controleren.'],['kind'=>'payslip','document_id'=>$ids[$period]['payslip'],'iban'=>$p['salary']['iban'],'name'=>$p['salary']['name'],'reference'=>'Salaris '.$period,'amount_cents'=>$salary['amount_cents'],'state'=>$p['salary_held']?'review':'pending','note'=>$p['salary_held']?'Eerdere salarisafstemming staat nog open; geen extra betaling automatisch voorbereid.':'Netto bedrag uit het loonstrookje; controleer of dit al is betaald of ingepland.']];AVBK_Company_Payroll::save_payments($id,$period,$entries);$prepared++;}echo wp_json_encode(['documents'=>count($p['documents']),'periods_prepared'=>$prepared]);return;}
if($p['operation']==='preview'){$data=AVBK_Company_Payroll_Automation::reminder($id,$p['date']);echo wp_json_encode(['html'=>AVBK_Company_Payroll_Automation::html($data),'qr_count'=>count(array_filter($data['items'],fn($v)=>!$v['paid_on']))]);return;}
if($p['operation']==='send'){echo wp_json_encode(['status'=>AVBK_Company_Payroll_Automation::send($id)]);return;}
if($p['operation']==='status'){update_option('avbk_payroll_automation_status_'.$id,$p['status'],false);echo '{}';return;}
throw new RuntimeException('Unknown payroll operation');
'''

def worker(config,operation,**extra):
    payload={'company_id':config['company_id'],'user_id':config['user_id'],'operation':operation,**extra}
    command=['docker','compose','-f',config.get('compose_file','/opt/docker/scripts/docker-compose.yml'),'exec','-T',config.get('wpcli_service','wpcli-company'),'wp','eval',WORKER]
    result=subprocess.run(command,input=json.dumps(payload),capture_output=True,text=True,timeout=120)
    if result.returncode:raise RuntimeError('WordPress payroll operation failed; inspect private log')
    return json.loads(result.stdout)

def private_write(path,data):
    if path.is_symlink():raise RuntimeError('Private path is a symlink')
    with path.open('wb') as f:os.chmod(path,0o600);f.write(data)

def collect(config,now,stage):
    profile_path=Path(config['mail_profile'])
    if profile_path.stat().st_mode & 0o077:raise RuntimeError('Mailbox credentials must be private')
    profile=json.loads(profile_path.read_text());documents=[];issues=[]
    first=(now.date().replace(day=1)-dt.timedelta(days=40)).replace(day=1)
    before=now.date()+dt.timedelta(days=1)
    with imaplib.IMAP4_SSL(profile['host'],profile.get('port',993),ssl_context=ssl.create_default_context(),timeout=25) as c:
        c.login(profile['username'],profile['password'])
        if c.select(profile.get('folder','INBOX'),readonly=True)[0]!='OK':raise RuntimeError('Read-only mailbox access failed')
        status,ids=c.uid('search',None,'SINCE',first.strftime('%d-%b-%Y'),'BEFORE',before.strftime('%d-%b-%Y'),'SUBJECT','salaris')
        if status!='OK' or len(ids[0].split())>100:raise RuntimeError('Payroll search failed or exceeded limit')
        for uid in ids[0].split():
            status,parts=c.uid('fetch',uid,'(RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC DELIVERED-TO X-ORIGINAL-TO SUBJECT MESSAGE-ID)])')
            part=next((x for x in parts if isinstance(x,tuple)),None)
            if status!='OK' or not part:continue
            header=BytesParser(policy=policy.default).parsebytes(part[1]);senders=[a.lower() for _,a in getaddresses(header.get_all('From',[]))]
            recipients=[a.lower() for _,a in getaddresses([str(v) for k in ['To','Cc','Delivered-To','X-Original-To'] for v in header.get_all(k,[])])]
            if not set(senders).intersection(config['approved_senders']) or profile['address'].lower() not in recipients:continue
            size=re.search(rb'RFC822.SIZE (\d+)',part[0])
            if not size or int(size[1])>15*1024*1024:issues.append('Salary message too large');continue
            status,parts=c.uid('fetch',uid,'(BODY.PEEK[])');raw=next((x[1] for x in parts if isinstance(x,tuple)),None)
            if status!='OK' or raw is None:issues.append('Salary message could not be read');continue
            message=BytesParser(policy=policy.default).parsebytes(raw)
            for part in message.walk():
                filename=part.get_filename()
                if not filename or not filename.lower().endswith('.pdf'):continue
                data=part.get_payload(decode=True)
                if not data or len(data)>MAX_BYTES:issues.append('Salary PDF too large or empty');continue
                digest=hashlib.sha256(data).hexdigest();private_write(stage/(digest+'.pdf'),data)
                try:details=parse_document(extract(data),config)
                except Exception:issues.append('Unsupported salary PDF; manual review required');continue
                if not first.strftime('%Y-%m')<=details['period']<=now.strftime('%Y-%m'):issues.append('Salary PDF period outside search range');continue
                source='Automatisch gelezen salarisbericht · '+str(message.get('Subject',''))+' · Message-ID '+str(message.get('Message-ID',''))
                if details['kind']=='payroll_tax':source+=' · opgave EUR '+format(Decimal(details['amount_cents'])/100,'.2f')+' · uiterlijk '+details['due_date']+' · kenmerk '+details['reference']
                documents.append({**details,'source':source[:1900],'filename':Path(filename.replace('\\','/')).name,'encoded':base64.b64encode(data).decode(),'sha256':digest})
    unique={d['sha256']:d for d in documents};documents=list(unique.values())
    grouped={}
    for d in documents:grouped.setdefault((d['period'],d['kind']),[]).append(d)
    bad={period for (period,kind),items in grouped.items() if len(items)>1}
    if bad:issues.append('Multiple payroll versions for a period; manual review required')
    return [d for d in documents if d['period'] not in bad],issues

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--config',type=Path,required=True);parser.add_argument('--scheduled',action='store_true');parser.add_argument('--dry-run',action='store_true');parser.add_argument('--preview-date');args=parser.parse_args()
    os.umask(0o077)
    if args.config.stat().st_mode & 0o077:raise RuntimeError('Scheduler configuration must be private')
    config=json.loads(args.config.read_text());globals()['_active_config']=config;now=dt.datetime.now(ZoneInfo('Europe/Amsterdam'))
    stage=Path(config['state_dir']);stage.mkdir(mode=0o700,parents=True,exist_ok=True)
    lock=(stage/'run.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    context=worker(config,'context');settings=context['settings']
    if not settings.get('enabled'):return
    state_path=stage/'state.json';state=json.loads(state_path.read_text()) if state_path.exists() else {}
    if args.scheduled and not due(now,settings,state):return
    if args.preview_date and not args.dry_run:raise RuntimeError('A simulated date is only allowed in preview mode')
    documents,issues=collect(config,now,stage)
    if args.dry_run:
        preview=worker(config,'preview',date=args.preview_date or str(now.date()));private_write(stage/'reminder-preview.html',preview['html'].encode())
        print(json.dumps({'dry_run':True,'documents_found':len(documents),'issues':issues,'qr_count':preview['qr_count'],'mail_sent':False}));return
    if issues:
        status={'last_attempt_date':str(now.date()),'last_check':now.isoformat(),'issues':issues,'mail_status':'needs_review'}
        private_write(state_path,json.dumps(status).encode());worker(config,'status',status=status)
        print(json.dumps(status));return
    if documents:
        if not context['salary']:raise RuntimeError('Approved salary recipient missing')
        worker(config,'import',documents=documents,salary=context['salary'],salary_held=context['salary_held'],baseline_period=context['baseline_period'])
    result=worker(config,'send')
    current={d['kind'] for d in documents if d['period']==now.strftime('%Y-%m')}
    status={'last_attempt_date':str(now.date()),'last_check':now.isoformat(),'documents_found':len(documents),'issues':issues,'mail_status':result['status']}
    if current=={'payslip','payroll_tax'} and not issues and result['status'] in ['sent','already_sent']:status['completed_month']=now.strftime('%Y-%m')
    private_write(state_path,json.dumps(status).encode());worker(config,'status',status=status)
    print(json.dumps(status))

if __name__=='__main__':
    sys.path.insert(0,str(Path(__file__).resolve().parent/'lib'))
    try:main()
    except Exception as error:
        try:
            if globals().get('_active_config'):
                worker(_active_config,'status',status={'last_check':dt.datetime.now(ZoneInfo('Europe/Amsterdam')).isoformat(),'mail_status':'needs_review','issues':['Automatische verwerking mislukt; controleer de laatste herinnering en bronbestanden.']})
        except Exception:pass
        print('Payroll automation failed; private configuration and provider details withheld.',file=sys.stderr)
        sys.exit(1)
