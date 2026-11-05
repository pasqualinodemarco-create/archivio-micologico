"""Run integration tests against a disposable, installed WordPress instance.
AM_WP_ROOT=/path/to/wp AM_TEST_PHP=/path/to/php-wrapper python3 tests/run_integration.py
Requires a test_admin WordPress administrator. Never run on production.
"""
import json,os,pathlib,subprocess,sys,tempfile,shutil
base=pathlib.Path(__file__).resolve().parent
cases=[]
for role in ['administrator','editor','author','contributor','subscriber']:
 for kind in ['caps','review','update','delete','settings','ui','upload']:
  cases.append(dict(kind=kind,role=role))
for kind in ['review','update','delete','settings']:
 cases.append(dict(kind=kind,role='editor',bad_nonce=True))
 cases.append(dict(kind=kind,role='guest'))
for kind in ['review','update']:
 cases.append(dict(kind=kind,role='editor',revoke_object=True))
for status in ['draft','pending','publish','private']:
 cases.append(dict(kind='submit',role='editor',status=status))
cases += [dict(kind='update',role='editor',clear_media=True),dict(kind='update',role='editor',extra_author=True),dict(kind='delete',role='editor',wrong_type=True),dict(kind='delete',role='editor',method='GET'),dict(kind='fallback',role='editor')]
cases += [dict(kind='delete',role='editor',trash_disabled=True)]
for role in ['editor','author']:
 for kind in ['tax_add','tax_update','tax_delete','tax_front']:
  cases.append(dict(kind=kind,role=role))
for role in ['editor','author']:
 cases.append(dict(kind='assets',role=role))
cases += [dict(kind='info',role='administrator'),dict(kind='info',role='editor'),dict(kind='info',role='author'),dict(kind='info_missing',role='editor'),dict(kind='changelog_parser',role='editor')]
results=[]
env=dict(os.environ,AM_TEST_DISPOSABLE='1')
for case in cases:
 case_dir=tempfile.TemporaryDirectory(prefix='am-case-')
 db_path=pathlib.Path(case_dir.name)/'db.sqlite'
 shutil.copyfile(os.environ['AM_TEST_DB_BASE'],db_path)
 env['AM_TEST_DB']=str(db_path)
 p=subprocess.run([os.environ['AM_TEST_PHP'],str(base/'integration.php'),json.dumps(case)],capture_output=True,text=True,env=env)
 marker='AMTEST:'
 if marker not in p.stdout:
  results.append({'case':case,'passed':False,'output':p.stdout,'stderr':p.stderr});continue
 result=json.loads(p.stdout.split(marker)[-1].strip())
 result['passed']=bool(result['checks']) and all(result['checks'].values())
 results.append(result)
 print(('PASS' if result['passed'] else 'FAIL'),json.dumps(case),'' if result['passed'] else json.dumps(result))
pathlib.Path(os.environ.get('AM_TEST_RESULTS','/tmp/am-integration-results.json')).write_text(json.dumps(results,indent=2))
print(f"{sum(r['passed'] for r in results)}/{len(results)} scenarios passed")
sys.exit(0 if all(r['passed'] for r in results) else 1)
