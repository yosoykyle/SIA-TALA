<div align="center">

# PyJobShop: Solving scheduling problems with constraint programming in Python

</div>

Leon Lan, Joost Berkhout Department of Mathematics, Vrije Universiteit Amsterdam, {l.lan,joost.berkhout}@vu.nl

This paper presents PyJobShop, an open-source Python library for solving scheduling problems with constraint programming. PyJobShop provides an easy-to-use modeling interface that supports a wide variety of scheduling problems, including well-known variants such as the flexible job shop problem and the resource-constrained project scheduling problem. PyJobShop integrates two state-of-the-art constraint programming solvers: Google's OR-Tools CP-SAT and IBM ILOG's CP Optimizer. We leverage PyJobShop to conduct large-scale numerical experiments on more than 9,000 benchmark instances from the machine scheduling and project scheduling literature, comparing the performance of OR-Tools and CP Optimizer. While CP Optimizer performs better on permutation scheduling and large-scale problems, OR-Tools is highly competitive on job shop scheduling and project scheduling problems-while also being fully open-source. By providing an accessible and tested implementation of constraint programming for scheduling, we hope that PyJobShop will enable researchers and practitioners to use constraint programming for real-world scheduling problems.

Key words: machine scheduling, project scheduling, constraint programming, open source, Python

## 1. Introduction

Scheduling is a key process in both manufacturing and service sectors, as it involves the efficient allocation of resources to tasks over time. Challenges in scheduling span many diverse domains from semiconductor manufacturing to construction project planning-making it one of the most intensively researched topics in operations research (Potts and Strusevich 2009). Over the past several decades, researchers have developed a wide range of models, evolving from simple single-machine settings to more complex job shop environments, with solution techniques that vary from straightforward dispatching rules to advanced metaheuristic algorithms.

Constraint programming (CP), a technique rooted in the field of artificial intelligence, has emerged as a promising technique to address scheduling problems, outperforming the traditional mixed-integer linear programming (MILP) approach (Ku and Beck 2016, Naderi et al. 2023). Its interval-based modeling language results in formulations that are more intuitive and compact than their MILP counterparts, while constraint propagation combined with search techniques make CP highly effective at obtaining high-quality solutions for scheduling (Laborie et al. 2018). This effectiveness has led to the widespread adoption of CP in solving scheduling problems over the past decade (Naderi et al. 2023).

Motivated by this increasing interest in CP, we present PyJobShop, an open-source Python package to solve scheduling problems with CP. PyJobShop provides an easy-to-use modeling interface that allows users to solve a large variety of machine and project scheduling problems without having to understand the specific CP implementation details. PyJobShop implements a general scheduling model and integrates two state-of-the-art CP solvers: Google's OR-Tools CP-SAT and IBM ILOG's CP Optimizer. The package features comprehensive documentation, extensive testing, and extensible CP models, and our code is open-source under the permissive MIT License. The contributions of our work can be further categorized as follows.

general-purpose scheduling model implemented with CP. Inspired by the review on the flexible job shop problem by Dauzère-Pérès et al. (2024), we introduce a general-purpose scheduling model that forms the basis of PyJobShop. This approach provides a single interface to address a wide variety of scheduling variants, including machine scheduling problems and also variants from the project scheduling literature. PyJobShop's scheduling model is implemented using Google's OR-Tools CP-SAT (Perron and Furnon 2024) and IBM ILOG's CP Optimizer (Laborie et al. 2018). In line with academic conventions, we refer to OR-Tools CP-SAT as OR-Tools and IBM ILOG's CP Optimizer as CP Optimizer. OR-Tools is an open-source solver that integrates CP and SAT solving techniques through lazy clause generation (Stuckey 2010) and has demonstrated state-of-the-art performance by consistently achieving first place in the MiniZinc CP challenge since 2018 (MiniZinc 2025). In contrast, CP Optimizer is a commercial solver that stems from ILOG's long history in CP (Baptiste et al. 2001). It is widely adopted within the scheduling community and is recognized for its strong performance, having successfully closed many long-standing open instances (Laborie et al. 2018).

Performance comparison between OR-Tools and CP Optimizer. We use PyJobShop to conduct a large-scale numerical experiment to compare the performance between OR-Tools and CP Optimizer using more than 9,000 benchmark instances from the machine scheduling and project scheduling literature. Our results show that OR-tools is highly competitive with CP Optimizer

delivering strong results on job shop and project scheduling problems while also achieving superior lower bounds. CP Optimizer, on the other hand, demonstrates superior scalability for permutation scheduling problems and large-scale problems. In addition, both OR-Tools and CP Optimizer find many new best-known solutions on benchmark instances from the project scheduling literature, further showcasing the strengths of CP in addressing these types of scheduling problems.

Open-source, tested and documented. Our final contribution is an open-source, tested, and documented software package that addresses a significant gap in the scheduling literature, where many implementations remain unpublished and are difficult to reproduce. We are aware of

two related scheduling software projects: SSP-3M (Márquez et al. 2024), an open-source framework for shop scheduling problems focused on designing heuristics, and the Job Shop Scheduling Benchmark (Reijnen et al. 2023), a benchmark library focused on shop scheduling problems with special emphasis on reinforcement learning methods. PyJobShop distinguishes itself through its comprehensive documentation, extensive test coverage, modern CP solver integration, and support for multiple scheduling variants through a single interface. Our focus on software makes PyJobShop particularly suitable for both research extensions and practical applications, and all code, data, and results are open-sourced to promote reproducibility and future development.

The outline of the remaining paper is as follows. In Section 2, we describe the scheduling model of PyJobShop and present its CP formulation. Section 3 gives an overview of all problem variants that PyJobShop supports. The PyJobShop software package is presented and demonstrated in Section 4. Section 5 describes and presents the large-scale numerical experiments. The paper is concluded in Section 6.

## 2. Problem description

In this section, we describe PyJobShop's scheduling model. In Section 2.1, we introduce the notation and preliminaries and informally describe the problem. In Section 2.2, we present the CP formulation. In the following, we assume that all numerical values are integral because CP solvers generally do not support continuous values.

## 2.1. Problem notation and preliminaries

Let J be the set of jobs, R the set of resources, T the set of tasks, and M the set of modes. A job $ j\in J $ represents a collection of tasks whose completion influences the objective. Each job $ j\in J $ has a set of related tasks $ T_{j}\subseteq T $ , a release date $ r_{j}\geq 0 $ when the job becomes available, a deadline $ \overline{d_{j}}\geq 0 $ by which the job must be completed, and an optional due date $ d_{j}\geq 0 $ by which the job is ideally completed, and a weight $ w_{j}\geq 0 $ reflecting its priority.

A resource $ r\in R $ is used to process tasks, and the set of resources R is partitioned into three disjoint sets $ R=R^{\mathrm{machine}}\cup R^{\mathrm{renewable}}\cup R^{\mathrm{non-renewable}} $ . A machine $ r\in R^{\mathrm{machine}} $ is a resource that can process only one task at a time and can handle sequencing constraints. A renewable resource $ r\in R^{\mathrm{renewable}} $ is a resource that has capacity $ Q_{r}\geq0 $ at each time period. In contrast, a non-renewable resource $ r\in R^{\mathrm{non-renewable}} $ is a resource that can process at most $ Q_{r}\geq0 $ demand in total over the entire time horizon.

A task $ t\in T $ is the smallest atomic unit that needs to be scheduled. For each task $ t\in T $ , we define a set of processing modes $ M_{t}\subseteq M $ , where exactly one mode must be selected. A mode represents

one possible way to process a task. Each mode $ m\in M $ specifies a processing duration $ p_{m}\geq 0 $ , a set of required resources $ R_{m}\subseteq R $ , and resource demands $ q_{mr}\geq 0 $ for each resource $ r\in R_{m}\setminus R^{\mathrm{machine}}. $

We classify constraints between tasks into three categories: timing, assignment, and sequencing constraints. Each constraint is formally represented as a tuple in a constraint set $ C^{\mathrm{ConstraintType}} $ for a specific constraint type.

Timing constraints define temporal relationships between two tasks i and k, with an optional delay $ l\in\mathbb{Z} $ . These are represented by four sets:

- $ \forall(i,k,l)\in C^{\mathrm{StartBeforeStart}} $ : Task i must start before task k starts by at least l time units

- $ \forall(i,k,l)\in C^{\mathrm{StartBeforeEnd}} $ : Task i must start before task k ends by at least l time units

- $ \forall(i,k,l)\in C^{\mathrm{EndBeforeStart}} $ : Task i must end before task k starts by at least l time units

- $ \forall(i,k,l)\in C^{\mathrm{EndBefor eEnd}} $ : Task i must end before task k ends by at least l time units

Assignment constraints govern resource allocation decisions between tasks. Let $ m_{i} $ and $ m_{k} $ be the selected modes for tasks i and k, with corresponding resource sets $ R_{m_{i}} $ and $ R_{m_{k}} $ . Two types of assignment constraints exist:

- $ \forall(i,k)\in C^{\mathrm{IdenticalResources}} $ : Tasks i and k must use the same resources, i.e., $ R_{m_{i}}=R_{m_{k}} $

- $ \forall(i,k)\in C^{\mathrm{DifferentResources}} $ : Tasks i and k must use different resources, i.e., $ R_{m_{i}}\cap R_{m_{k}}=\emptyset $

Sequencing constraints impose restrictions between tasks and apply only when machines are involved. Let the overlapping machines of tasks i and k be $ R_{ik}^{\mathrm{machine}}=\{r\in R^{\mathrm{machine}}:r\in R_{u}\wedge r\in $ $ R_{v} $ for some $ u\in M_{i},v\in M_{k} $}.

- $ \forall(i,k)\in C^{\mathrm{Consecutive}} $ : Task i must immediately precede task k for all machines $ r\in R_{ik}^{\mathrm{machine}} $ they are both scheduled on

- $ \forall(i,k,r,l)\in C^{\mathrm{SetupTime}} $ : When task k is scheduled after task i on machine $ r\in R_{ik}^{\mathrm{machine}} $ , then there is a setup time of l time units

A feasible solution for the scheduling problem specifies for each task $ t\in T $: (i) when it starts and ends and (ii) which processing mode is selected, while respecting all constraints. The objective is to minimize a weighted sum of common scheduling objective functions. We define this in detail in the next section.

## 2.2. Constraint programming model

2. 2.1. Preliminaries. This section provides a brief introduction to important CP concepts related to scheduling. It is intended for readers who are familiar with basic optimization concepts, such as variables and constraints, but have limited knowledge of CP. A detailed explanation of CP is outside the scope of this paper. For a general introduction to CP from an operations research perspective, we refer readers to Baptiste et al. (2001), Kanet et al. (2004), Pesant (2014).

CP is a paradigm for addressing constraint satisfaction problems. A constraint satisfaction problem consists of a finite set of variables, each with a discrete domain, and a set of constraints that must be satisfied. CP systematically narrows down the domains of variables through a technique called constraint propagation, which ensures that constraints are effectively communicated across variables. In addition to constraint propagation, CP also employs search techniques, such as backtracking and large neighborhood search, to systematically explore possible assignments when propagation alone is insufficient to determine a solution. CP is designed to handle a wide range of (non-linear) constraints, including mathematical, logical, and global constraints. This flexibility is essential in capturing the unique aspects of scheduling problems, where specialized global constraints such as NoOverlap and Cumulative are highly effective at domain reduction and compactly formulate the scheduling constraints, while an equivalent MILP formulation would require a large number of linear constraints and big-M reformulations.

A central element of modern CP solvers such as OR-Tools and CP Optimizer is interval variables (Laborie and Rogerie 2008). An interval variable $ \nu $ is a special decision variable that is composed of four other decision variables: the start time variable $ \nu^{\mathrm{start}} \geq 0 $ , the duration variable $ \nu^{\mathrm{duration}} \geq 0 $ , the end time variable $ \nu^{\mathrm{end}} \geq 0 $ , and the presence variable $ \nu^{\mathrm{present}} \in \{0,1\} $ . Interval variables impose constraints between the start, duration, and end variables depending on their presence status. If the interval is present $ (\nu^{\mathrm{present}}=1) $ , then $ \nu^{\mathrm{duration}}=\nu^{\mathrm{end}}-\nu^{\mathrm{start}} $ is enforced. When the interval is absent $ (\nu^{\mathrm{present}}=0) $ , then there is no such enforcement. Moreover, specialized scheduling constraints such as NoOverlap and Cumulative implicitly take into account the presence of interval variables; if an interval is not present, then it is effectively ignored by the constraint.

In CP, there is a close integration between modeling languages and constraint programming solvers. For instance, CP Optimizer implements the so-called Span constraint which can be used to relate interval variables to each other, whereas OR-Tools does not, resulting in two different solver-specific formulations. Many recent scheduling studies have used CP Optimizer and present a CP formulation using CP Optimizer-specific syntax from Laborie et al. (2018). As a result, the description of OR-Tools models is underrepresented in the scheduling academic literature, and in the following, we formulate PyJobShop's CP model using OR-Tools syntax.

2. 2.2. Model formulation. This section presents our model's variables, constraints, and objective functions in sequence.

Variables. We introduce the following interval variables:

- $ \phi_{j} $ : interval variable for each job $ j\in J $ with $ \phi_{j}^{\mathrm{p r e sent}}=1 $

- $ \tau_{t} $ : interval variable for each task $ t\in T $ with $ \tau_{t}^{\mathrm{p r e s e n t}}=1 $

- $ \mu_{m} $ : interval variable for each mode $ m\in M $ with $ \mu_{m}^{\mathrm{duration}}=p_{m} $

Job $ \left( \phi_{j} \right) $ and task $ \left( \tau_{t} \right) $ interval variables are always present, whereas $ \mu_{m} $ can be optional. The mode duration $ \mu_{m}^{\mathrm{duration}} $ is set to be fixed to the mode duration, while the task duration and job duration follow from the constraints as defined below. The relationship between variables is further explained by the constraints in the next paragraphs.

Constraints. We present the constraints organized by each logical category. This follows the same structure as in the code implementation.

Linking jobs to tasks. Job interval variables are not directly scheduled. Instead, a job starts and ends with its earliest and latest task, respectively.

$$
\phi_ {j} ^ {\mathrm {s t a r t}} = \min _ {t \in T _ {j}} \tau_ {t} ^ {\mathrm {s t a r t}} \quad \forall j \in J
$$

$$
\phi_ {j} ^ {\mathrm {e n d}} = \max _ {t \in T _ {j}} \tau_ {t} ^ {\mathrm {e n d}} \quad \forall j \in J
$$

Constraints (1a) set the start time of job j equal to the earliest start time among all its tasks. Similarly, Constraints (1b) ensure that the job's end time corresponds to the completion time of its last task.

Linking tasks to modes. Task intervals and mode intervals interact in a structured manner. Each task requires the selection of exactly one mode. The interval variable of a task always starts and ends simultaneously with the interval variables of its modes, regardless of which mode is selected. The selected mode determines the duration of the corresponding task interval.

$$
\sum_ {m \in M _ {t}} \mu_ {m} ^ {\mathrm {p r e s e n t}} = 1 \quad \forall t \in T
$$

$$
\mu_ {m} ^ {\mathrm {s t a r t}} = \tau_ {t} ^ {\mathrm {s t a r t}} \quad \forall t \in T, m \in M _ {t}
$$

$$
\mu_ {m} ^ {\mathrm {e n d}} = \tau_ {t} ^ {\mathrm {e n d}} \quad \forall t \in T, m \in M _ {t}
$$

$$
\mu_ {m} ^ {\mathrm {p r e s e n t}} \Longrightarrow \mu_ {m} ^ {\mathrm {d u r a t i o n}} = \tau_ {t} ^ {\mathrm {d u r a t i o n}} \quad \forall t \in T, m \in M _ {t}
$$

Constraints (1c) ensure that exactly one mode is selected for each task. Constraints (1d) and Constraints (1e) ensure that the mode variable and task interval start and end together, respectively. Constraints (1f) enforce that if the given mode is selected, the duration is synchronized with the corresponding task duration. Additionally, the selected mode interval effectively represents the task with a specific resource allocation, as defined next.

Resource constraints. Each resource type has a specific rule that dictates how many tasks it can process. Denote $ M_{r}^{R}=\{m\in M:r\in R_{m}\} $ as the modes requiring resource $ r\in R $ . The following constraints ensure that resource utilization constraints are respected.

$$
\mathrm {N o O v e r l a p} \left(\left\{\mu_ {m}: m \in M _ {r} ^ {R} \right\}\right) \quad \forall r \in R ^ {\mathrm {m a c h i n e}}
$$

$$
\mathrm {C u m u l a t i v e} \left\{\left\{\mu_ {m}: m \in M _ {r} ^ {R} \right\}, \left\{q _ {m r}: m \in M _ {r} ^ {R} \right\}, Q _ {r} \right\} \quad \forall r \in R ^ {\mathrm {r e n e w a b l e}}
$$

$$
\sum_ {m \in M _ {r} ^ {R}} \mu_ {m} ^ {\mathrm {p r e s e n t}} \cdot q _ {m r} \leq Q _ {r} \quad \forall r \in R ^ {\mathrm {n o n - r e n e w a b l e}}
$$

Constraints (1g) ensure that mode variables that use a given machine cannot overlap, that is, a machine can only process one task at a time. Constraints (1h) restrict that mode variables do not exceed the demand of the requested renewable resource at any point in time, while Constraints (1i) ensure that the total demand for a non-renewable resource is not exceeded. As stated before, the global constraints NoOverlap and Cumulative explicitly take into account the presence of the interval variables; if a mode interval variable is not present, then it is effectively ignored by the constraint.

Timing constraints. Based on the four sets of tuples capturing the timing constraints, the timing constraints are formally defined as follows:

$$
\tau_ {i} ^ {\mathrm {s t a r t}} + l \leq \tau_ {k} ^ {\mathrm {s t a r t}} \quad \forall (i, k, l) \in C ^ {\mathrm {S t a r t B e f o r e S t a r t}}
$$

$$
\tau_ {i} ^ {\mathrm {s t a r t}} + l \leq \tau_ {k} ^ {\mathrm {e n d}} \quad \forall (i, k, l) \in C ^ {\mathrm {S t a r t B e f o r e E n d}}
$$

$$
\tau_ {i} ^ {\mathrm {e n d}} + l \leq \tau_ {k} ^ {\mathrm {s t a r t}} \quad \forall (i, k, l) \in C ^ {\mathrm {E n d B e f o r e S t a r t}}
$$

$$
\tau_ {i} ^ {\mathrm {e n d}} + l \leq \tau_ {k} ^ {\mathrm {e n d}} \quad \forall (i, k, l) \in C ^ {\mathrm {E n d B e f o r e E n d}}
$$

Each of the constraints in (1j)-(1m) restricts the timing between pairs of task variables.

Assignment constraints. Some pairs of tasks must use either identical or different resources. For any such pair of tasks i and k, we need to ensure their selected modes are compatible.

$$
\mu_ {m _ {i}} ^ {\mathrm {p r e s e n t}} \leq \sum_ {m _ {k} \in M _ {k}} \mu_ {m _ {k}} ^ {\mathrm {p r e s e n t}} \quad \forall (i, k) \in C ^ {\mathrm {I d e n t i c a l R e s o u r c e s}}, m _ {i} \in M _ {i}
$$

$$
\mu_ {m _ {i}} ^ {\mathrm {p r e s e n t}} \leq \sum_ {\substack {m _ {k} \in M _ {k} \\ \mathrm {s.t.} R _ {m _ {k}} \cap R _ {m _ {i}} = \emptyset}} \mu_ {m _ {k}} ^ {\mathrm {p r e s e n t}} \quad \forall (i, k) \in C ^ {\mathrm {D i f f e r e n t R e s o u r c e s}}, m _ {i} \in M _ {i}
$$

Constraints (1n) ensure that if mode $ m_{i} $ is selected for task i, then at least one mode with identical resources must be selected for task k. Similarly, Constraints (1o) ensure that if mode $ m_{i} $ is selected for task i, then at least one mode with disjoint resources must be selected for task k.

Sequencing constraints. Setting up sequencing constraints in OR-Tools requires more setup because OR-Tools does not provide an interface for sequencing variables like CP Optimizer (Laborie et al.

[2018]. Instead, with OR-Tools, a sequence of intervals can be represented by a complete graph combined with the global Circuit constraint to select a specific ordering of the intervals. For each machine $ r\in R^{\mathrm{machine}} $ , define a complete graph where the node set $ V_{r}=M_{r}^{R}\cup\{0\} $ includes all modes that require machine r plus a dummy node 0. The arcs consist of all possible node pairs, including self-loops. We introduce binary variables $ B_{r}=\{b_{ruv}\in\{0,1\}:u,v\in V_{r}\} $ , where $ b_{ruv}=1 $ indicates that arc (u,v) is selected in the graph of machine r, and 0 otherwise. Let $ t_{m} $ denote the task associated with mode m. Then, the sequencing constraints are specified as follows.

$$
\operatorname {C i r c u i t} \left(B _ {r}\right)
$$

$$
\forall r \in R ^ {\mathrm {m a c h i n e}}
$$

$$
b _ {r u v} \Longrightarrow \mu_ {u} ^ {\mathrm {p r e s e n t}} \wedge \mu_ {v} ^ {\mathrm {p r e s e n t}}
$$

$$
\forall u, v \in M _ {r} ^ {R}, r \in R ^ {\mathrm {m a c h i n e}}
$$

$$
b _ {r u u} \Longrightarrow \neg \mu_ {u} ^ {\mathrm {p r e s e n t}}
$$

$$
\forall u \in M _ {r} ^ {R}, r \in R ^ {\mathrm {m a c h i n e}}
$$

$$
b _ {r 0 0} \Longrightarrow \neg \mu_ {u} ^ {\mathrm {p r e s e n t}}
$$

$$
\forall u \in M _ {r} ^ {R}, r \in R ^ {\mathrm {m a c h i n e}}
$$

$$
b _ {r u v} \Longrightarrow \mu_ {u} ^ {\mathrm {e n d}} + s _ {t _ {u}, t _ {v}, r} \leq \mu_ {v} ^ {\mathrm {s t a r t}}
$$

$$
\forall u, v \in M _ {r} ^ {R}, r \in R ^ {\mathrm {m a c h i n e}}
$$

Constraints (1p) ensure that the selected arcs form a single sub-tour, starting and ending at the dummy node, thus establishing an order for the mode intervals on machine r. Constraints (1q) guarantee that if an arc is selected, the corresponding mode intervals must be present. For modes not part of the selected sub-tour, self-loops must be selected, and Constraints (1r) ensure that the corresponding mode interval is absent. Constraints (1s) address the special case where if the dummy self-arc is selected, all mode intervals must be absent. This occurs when a resource is not allocated any tasks. Constraints (1t) then ensure that there is an end-before-start relationship including setup times. If $ ( t_{u}, t_{v}, r, l) \in C^{\mathrm{SetupTime}} $ , then the setup time $ s_{t_{u}, t_{v}, r} $ is given by l, otherwise it is zero.

The consecutive constraints are defined as follows.

$$
\mu_ {u} ^ {\mathrm {p r e s e n t}} \wedge \mu_ {v} ^ {\mathrm {p r e s e n t}} \Longrightarrow b _ {r u v} \quad \forall (i, k) \in C ^ {\mathrm {C o n s e c u t i v e}}, r \in R _ {i k} ^ {\mathrm {m a c h i n e}}, u \in M _ {i} \cap M _ {r} ^ {R}, v \in M _ {k} \cap M _ {r} ^ {R}
$$

This constraint ensures that if both modes u and v, belonging to tasks i and k respectively, are present, then it must be part of the sub-tour selected by the Circuit constraint. Consequently, this ensures that tasks i and k are scheduled consecutively on the set of machines that they are both allocated to.

Objective function. PyJobShop supports the following common objective functions:

- Makespan: $ \max_{t\in T} \tau_{t}^{\mathrm{end}} $

- Total weighted flow time: $ \sum_{j\in J} w_{j} \left( \phi_{j}^{\mathrm{end}}-r_{j} \right) $

- Total weighted tardiness: $ \sum_{j\in J} w_{j}\max(\phi_{j}^{\mathrm{end}}-d_{j},0) $

- Total weighted earliness: $ \sum_{j\in J} w_{j}\max \left(d_{j}-\phi_{j}^{\mathrm{end}},0\right) $

- Total weighted number of tardy jobs: $ \sum_{j\in J}w_{j}\mathbb{1}\left\{\phi_{j}^{\mathrm{end}}>d_{j}\right\} $

- Maximum tardiness: $ \max_{j\in J}w_{j}\max \left(\phi_{j}^{\mathrm{end}}-d_{j},0\right) $

- Maximum lateness: $ \max_{j\in J}w_{j}\left(\phi_{j}^{\mathrm{end}}-d_{j}\right) $

Let F denote the set of objective functions and let $ w^{f} $ denote the weight of objective $ f\in F $ . The overall objective is to find a solution x in the set of feasible solutions X that minimizes the weighted sum of all objective functions:

$$
\min _ {x \in X} \sum_ {f \in F} w ^ {f} \cdot f (x)
$$

## 3. Supported scheduling problems

PyJobShop's scheduling model, introduced in Section 2, supports a wide range of scheduling problems. In this section, we describe several common scheduling variants that can be modeled and solved with PyJobShop. We discuss machine scheduling variants in Section 3.1 and project scheduling variants in Section 3.2.

## 3.1. Machine scheduling

To demonstrate the supported machine scheduling variants, we rely on Graham's notation $ \alpha\mid\beta\mid\gamma $ which is widely used in the scheduling community to classify scheduling problem models (Graham et al.1979). The first field $ (\alpha) $ specifies the machine environment, the second field $ (\beta) $ specifies the constraints, and the third field $ (\gamma) $ specifies the objective. Table 1 presents the most common scheduling characteristics for each field based on the classification schemes described from Framinan et al. (2014), Pinedo (2016), Framinan et al. (2019), Dauzere-Pérs et al. (2024).

In terms of machine environments, all classic environments (1, P, F, HF, O, J, FJ; see Table 1 for their meaning) are supported since most of them can be cast as a variant of the flexible job shop environment. Beyond the classic environments, an environment commonly found in realistic manufacturing settings is the assembly scheduling problem $ (\circ\rightarrow \circ) $ , which involves the scheduling of tasks in a concurrent fashion (Framinan et al. 2019). Assembly scheduling generalizes the order scheduling environment (Leung et al. 2005), where a job can consist of multiple tasks with their own processing sequence that need to be completed before it is considered completed. For example, a common manufacturing environment is $ P m\to1 $ , which consists of parallel machines in the first stage that produce parts of some product, followed by a single assembly line that can only start when all individual parts have been produced.

Dauzère-Pérès et al. (2024) extends the environment field with multi-mode (MM), flexible sequencing (FS), multi-resource (MR), flexible processing planning (FP), and distributed (D). Multiple modes (MM) are supported and described as a core part of our model. Flexible sequencing

(FS) allows interchanging the order in which a job's tasks are performed, for instance, processing tasks 1, 2, 3 or 1, 3, 2. Additionally, some tasks may not be processed simultaneously (e.g., tasks 2 and 3), which can achieved by introducing a fictitious machine and modes to prevent overlap Dauzere-Perres et al. 2024). Multi-resource (MR) is a generalization of multiple modes, where each mode describes a set of required skills that must be satisfied by any resource that possesses that skill. A more fitting name for multi-resource is multi-skilled, which is the terminology used in the project scheduling literature Snauwaert and Vanhoucke 2023). Multi-resource is not supported in PyJobShop but can be implemented as a multi-mode problem if the number of resource-skill combinations is limited. Flexible processing planning (FP) and distributed (D) scheduling define alternative routes for a job's tasks, meaning that there are multiple distinct ways to process a job, e.g., either by processing tasks 1 and 2 or by processing tasks 3 and 4. Both these extensions are not supported because they require tasks to be optional.

In terms of constraints, PyJobShop supports release dates $ ( r_{j} ) $ and deadlines $ (\bar{d}_{j}) $ , which restrict job start and completion times, as well as due dates $ ( d_{j} ) $ for tardiness-based objectives. Capacity-based resources (res) describe the use of renewable resources in machine scheduling problems (as opposed to only machines) and are explicitly part of our scheduling model. Bills of materials (bom), also known as arbitrary precedence graphs (Kasapidis et al. 2021), are supported since timing constraints can be arbitrarily imposed between tasks. Sequence-dependent setup times $ ( s_{ijk} ) $ are supported as sequencing constraints. Blocking (block) requires that a task can occupy a resource longer than its processing duration requires because its successor tasks can not yet start. This is supported by allowing variable task durations and generalized precedence constraints (end-at-start). Buffers $ ( b_{i} ) $ can be modeled by introducing additional resources for each machine, each with a capacity and precedence constraints with blocking. No waiting time (no-wait) between consecutive tasks is possible by combining end-before-start and start-before-end constraints. Task overlap (overlap) between tasks is possible as nothing restricts tasks from overlapping in a resource setting, only the resource capacity (cumulative) or one at a time (machine). Basic forms of machine breakdowns or unavailability (brkdwn) can be added by introducing dummy tasks that occupy a machine for a fixed period of time. General time lags $ ( d_{ii'}^{kk'} ) $ incorporate extra delays to the precedence constraints, which is supported if the time lag is machine-independent.

We have intentionally chosen not to support permutation constraints (prmu) due to the complexity of implementing them robustly alongside other features. Permutation constraints require a specific mapping between tasks and machines to ensure correctness, which is not feasible with the current interface. Additionally, we do not support no-idle constraints (no-idle), which require machines to continuously process tasks without any idle time between them. Batching problems (p-batch) are also unsupported, as they involve an additional decision regarding which tasks should

<div align="center">

Table 1 Classification scheme for machine scheduling problems. Supported features by PyJobShop are indicated with a black square, while unsupported features are indicated with a white square.

</div>

> **Source-image note:** The extracted OCR image is unavailable locally; its expiring signed URL was removed.

| Machine environments ($\alpha$)                  | Constraints ($\beta$)                  | Objectives ($\gamma$)                            |
| ------------------------------------------------ | -------------------------------------- | ------------------------------------------------ |
| ■ **1**: Single machine                          | ■ $r_j$: Release dates                 | ■ $C_{\max}$: Makespan                           |
| ■ **P**: Parallel machines                       | ■ $d_j$: Due dates                     | ■ **TWFT**: Total weighted flow time             |
| ■ **F**: Flow shop                               | ■ $\overline{d}_j$: Deadlines          | ■ **TWT**: Total weighted tardiness              |
| ■ **HF**: Hybrid flow shop                       | ■ **res**: Capacity-based resources    | ■ **TWE**: Total weighted earliness              |
| ■ **O**: Open shop                               | ■ $s_{ijk}$: Setup times               | ■ **TWNTJ**: Total weighted number of tardy jobs |
| ■ **J**: Job shop                                | ■ **bom**: Bills of materials          | ■ $T_{\max}$: Maximum tardiness                  |
| ■ **FJ**: Flexible job shop                      | ■ **block**: Jobs are blocked          | ■ $L_{\max}$: Maximum lateness                   |
| ■ $\circ \rightarrow \circ$: Assembly scheduling | ■ $b_i$: Buffers                       | □ $W_T$: Total workload of machines              |
| ■ **MM**: Multi-mode                             | ■ **no-wait**: Jobs may not wait       |                                                  |
| ■ **FS**: Flexible sequencing                    | ■ **overlap**: Overlapping tasks       |                                                  |
| □ **MR**: Multi-resource                         | ■ **brkdwn**: Breakdowns               |                                                  |
| □ **FP**: Flexible processing planning           | ■ $d_{ii'}^{kk'}$: General time lags   |                                                  |
| □ **D**: Distributed                             | □ **no-idle**: Machine cannot be idle  |                                                  |
|                                                  | □ **prmu**: Permutation constraint     |                                                  |
|                                                  | □ **p-batch**: Simultaneous processing |                                                  |
|                                                  | □ **prmp**: Pre-emption                |                                                  |


be processed simultaneously. Similarly, pre-emption (prmp) is not supported, as it requires making another decision about how to split tasks.

PyJobShop supports the most common objective functions, including makespan $ (C_{\mathrm{m a x}}) $ , total weighted flow time (TWFT), total weighted tardiness (TWT), total weighted earliness (TWE), the weighted number of tardy jobs (TWNTJ), maximum lateness $ (L_{\mathrm{m a x}}) $ , and maximum tardiness $ (T_{\mathrm{m a x}}) $ . These objective functions share a common feature: they are all based on the completion times of jobs or tasks. There is currently no support for objectives based on other factors, such as resource workload. For a detailed overview of relevant objectives in machine scheduling settings, we refer to Ostermeier and Deuse (2023).

## 3.2. Project scheduling

The original scope of PyJobShop was to specifically solve machine scheduling problems. However, because we introduced concepts that are also prevalent in the project scheduling literature, such as modes and capacity-based resources, there are many project scheduling variants that PyJobShop can also solve.

Project scheduling and machine scheduling share many similar ideas, but the nomenclature is slightly different (Demeulemeester and Herroelen 2006). Jobs are called projects, although it is common to only have one project, and tasks are referred to as activities and events. Resources

include renewable and non-renewable resources, or double-constrained resources, but also cumulative and partially renewable resources, the latter of the two which are not supported in PyJobShop. Machines are uncommon in the project scheduling literature, but there are variants, such as the multi-skilled project scheduling problem, that use disjunctive resources (Snauwaert and Vanhoucke 2023).

We outline here the most well-known project scheduling variants that are supported by PyJobShop. We refer to the surveys of Hartmann and Briskorn (2022) and Gomez Sánchez et al. (2023) for an extensive survey of the project scheduling variants. The project scheduling problem is the most basic variant, which considers tasks without any resource constraints, but tasks are constrained by precedence relationships. The resource-constrained project scheduling problem (RCPSP) introduces finite resources, each task requiring a subset of these resources for a fixed duration and resource requirement. The multi-mode variant of RCPSP (MMRCPSP) extends the RCPSP by allowing tasks to be executed in various modes, each with distinct duration and resource requirements, adding flexibility that is similar to the extension from job shops to flexible job shops. Another commonly studied variant of the RCPSP is the one with generalized precedence constraints (RCPSP/max) (Schutt et al. 2013). Generalized precedence relations express relations of start-to-start, start-to-end, end-to-start, and end-to-end between pairs of tasks, as described earlier in our scheduling model. Finally, the resource-constrained multi-project scheduling problem (RCMPSP) introduces multiple concurrent projects, each with a set of tasks that need to be completed. The goal of all these problem variants is to minimize the makespan.

## 4. Software

The PyJobShop package is developed in a GitHub repository located at https://github.com/ PyJobShop/PyJobShop. This repository contains the source code, including tests, documentation, and examples that introduce new users to PyJobShop. The documentation of PyJobShop is hosted at https://pyjobshop.org. Users can directly install PyJobShop from the Python package index using pip install pyjobshop, which comes with OR-Tools by default. Additional installation instructions for CP Optimizer are provided in the documentation.

PyJobShop borrows many ideas from PyVRP (Wouda et al. 2024), an open-source solver for vehicle routing problems. In particular, the simple modeling framework, extensive documentation, and numerous examples have been shown to bring much value to users of PyVRP from academia as well as industry.

In the following sections, we elaborate on the package structure, provide a modeling example and describe how to extend the package, respectively.

## 4.1. Package structure

The top-level package of the pyjobshop namespace contains most user components.

- ProblemData.py: Contains the ProblemData class, defining the problem data instance to be solved, as well as the Job, Task, Machine, Renewable, NonRenewable, Mode and Constraints classes.

- Model.py: The modeling interface to build a ProblemData instance step-by-step.

- Solution.py: The class that describes a solution.

- Result.py: The result of a solver run, including the best-found solution along with solver statistics.

- read.py: A function to read a variety of scheduling benchmark instances.

- solve.py: A dedicated solving function.

- cli.py: The command-line interface, mostly for internal use and benchmarking.

- solvers/: The solver module implements the scheduling model using constraint programming solvers, ortools and cpoptimizer. Each solver is encapsulated in a solver-specific Solver class, which manages implementations of Variables, Constraints, and Objective classes.

## 4.2. Example use

The primary interface for PyJobShop is the Model class, which provides an intuitive domain-specific interface for scheduling problems. Listing 1 presents a complete example of modeling and solving a flow shop problem. Users first create instance components (machines, jobs, tasks, modes, constraints) through dedicated model methods and then invoke the solve method to obtain a result object containing the solution and solver statistics. The solution can then be plotted using plot machine gantt from the pyjobshop.plot module, which plots a Gantt chart as shown in Figure 1. We refer to the documentation for more examples, which include more examples from machine scheduling as well as project scheduling.

## 4.3. Extending PyJobShop

While PyJobShop implements common constraints for scheduling problems, some users may require more specialized constraints. Users can easily extend the PyJobShop framework to suit their needs. Extensions often fall into one of the following categories: adding new constraints, adding new objective functions, or adding new decision variables. A common workflow for making changes is by modifying, in order, the following classes: ProblemData (how to represent the new feature as data), Model (how to interact with the new feature through the user interface) and the corresponding CP solver's Solver class (how to implement the new feature in terms of CP variables and constraints).

We are open to new contributions and encourage users to contribute with new features, examples, and documentation improvements. To discuss potential extensions or improvements, we recommend first opening an issue on the GitHub repository.

Listing 1: Modeling a flow shop problem.

```python

import random

from pyjobshop import Model

from pyjobshop.plot import plot_machine_gantt

random.seed(42)

model = Model()

machines = [model.add_machine() for idx in range(5)]

for job_idx in range(5):

    job = model.add_job()

    tasks = [model.add_task(job=job) for idx in range(5)]

    for idx in range(len(tasks)):

        task = tasks[idx]

        machine = machines[idx]

        processing_time = random.randint(1, 5)

        model.add_mode(task, machine, processing_time)

    for idx in range(len(tasks) - 1):

        pred = tasks[idx]

        succ = tasks[idx + 1]

        model.add_end_before_start(pred, succ)

result = model.solve(time_limit=10)

data = model.data()

solution = result.best

plot_machine_gantt(solution, data)

```

## 5. Numerical experiments

PyJobShop allows for easy modeling of many scheduling problems through one single interface. We leverage this to evaluate and compare the performance of OR-Tools and CP Optimizer on a large variety of scheduling instances. Section 5.1 covers the benchmark instances and Section 5.2 describes the computational aspects. The results are presented in Section 5.3, and we discuss the results in Section 5.4.

## 5.1. Benchmark instances

We conducted our experiments on 9,280 instances total, both from machine scheduling and project scheduling instances. Table 2 summarizes the instance characteristics. In the following, we describe in more detail the choices for these instances, discussing machine and project scheduling separately.

Machine scheduling instances. The first set covers a subset of machine scheduling instances used in Naderi et al. (2023). We consider nine different problem variants, including the job shop problem (JSP), flexible job shop problem (FJSP), no-wait permutation flow shop problem (NW-PFSP), non-permutation flow shop problem (NPFSP), hybrid flow shop problem (HFSP), permutation

<div align="center">

Figure 1 Gantt chart produced by the code from Listing 1. Each bar represents one task and the colors depict the job it belongs to.

</div>

> **Source-image note:** The extracted OCR image is unavailable locally; its expiring signed URL was removed.

```mermaid
gantt
    title Solution Schedule
    dateFormat YYYY-MM-DD
    axisFormat %d
    tickInterval 1day

    section Machine 0
    Job 1 — Operation 1 :m0j1, 2026-01-01, 1d
    Job 2 — Operation 1 :m0j2, 2026-01-02, 3d
    Job 3 — Operation 1 :m0j3, 2026-01-05, 3d
    Job 4 — Operation 1 :m0j4, 2026-01-08, 4d
    Job 5 — Operation 1 :m0j5, 2026-01-12, 4d

    section Machine 1
    Job 1 — Operation 2 :m1j1, 2026-01-02, 4d
    Job 2 — Operation 2 :m1j2, 2026-01-06, 2d
    Job 3 — Operation 2 :m1j3, 2026-01-08, 4d
    Job 4 — Operation 2 :m1j4, 2026-01-12, 3d
    Job 5 — Operation 2 :m1j5, 2026-01-16, 1d

    section Machine 2
    Job 1 — Operation 3 :m2j1, 2026-01-06, 3d
    Job 2 — Operation 3 :m2j2, 2026-01-09, 1d
    Job 3 — Operation 3 :m2j3, 2026-01-12, 3d
    Job 4 — Operation 3 :m2j4, 2026-01-15, 1d
    Job 5 — Operation 3 :m2j5, 2026-01-17, 3d

    section Machine 3
    Job 1 — Operation 4 :m3j1, 2026-01-09, 2d
    Job 2 — Operation 4 :m3j2, 2026-01-11, 4d
    Job 3 — Operation 4 :m3j3, 2026-01-15, 4d
    Job 4 — Operation 4 :m3j4, 2026-01-19, 4d
    Job 5 — Operation 4 :m3j5, 2026-01-23, 1d

    section Machine 4
    Job 1 — Operation 5 :m4j1, 2026-01-11, 2d
    Job 2 — Operation 5 :m4j2, 2026-01-15, 4d
    Job 3 — Operation 5 :m4j3, 2026-01-19, 3d
    Job 4 — Operation 5 :m4j4, 2026-01-23, 2d
    Job 5 — Operation 5 :m4j5, 2026-01-25, 1d
```

flow shop problem (PFSP), sequence-dependent setup times PFSP (SDST-PFSP), total completion time PFSP (TCT-PFSP) and total tardiness PFSP (TT-PFSP). We refer to Naderi et al. (2023) for a precise definition of these problem variants and benchmark instances.

Four out of nine selected variants require explicit permutation constraints (PFSP, SDST-PFSP, TCT-PFSP, TT-PFSP), which are not directly supported by PyJobShop. Nevertheless, to evaluate the performance between the two CP solvers and to replicate the study performed by Naderi et al. (2023), we have implemented permutation constraints specifically for this experiment. However, OR-Tools lacks an efficient way of implementing the permutation constraint, requiring the basic sequencing constraints from Section 2.2 that result in a large number of variables and constraints. This limitation forced us to restrict the instances to at most 100 jobs, as larger problems were computationally intractable. Finally, we note that the NW-PFSP does not require explicit permutation constraints as this can be implemented using end-to-start constraints, which implies a permutation in the context of flow shops.

We excluded three problem variants from Naderi et al. (2023): (i) the open shop problem because those instances are all trivially solved, (ii) the parallel machines problem because this problem

<div align="center">

Table 2 Summary of instance statistics of benchmark instances used for the numerical experiments. The table reports the minimum, average, and, maximum number of tasks and resources over all instances for the given statistic.

</div>

<table border="1"><tr><td></td><td></td><td></td><td colspan="3">#Tasks</td><td colspan="3">#Resources</td></tr><tr><td></td><td>Problem</td><td>#Instances</td><td>Min.</td><td>Avg.</td><td>Max.</td><td>Min.</td><td>Avg.</td><td>Max.</td></tr><tr><td rowspan="5">Non-permutation</td><td>JSP</td><td>242</td><td>36</td><td>511</td><td>2000</td><td>5</td><td>15</td><td>20</td></tr><tr><td>FJSP</td><td>289</td><td>12</td><td>322</td><td>1477</td><td>4</td><td>11</td><td>20</td></tr><tr><td>NW-PFSP</td><td>360</td><td>100</td><td>12610</td><td>48000</td><td>5</td><td>31</td><td>60</td></tr><tr><td>NPFSP</td><td>360</td><td>100</td><td>12610</td><td>48000</td><td>5</td><td>31</td><td>60</td></tr><tr><td>HFSP</td><td>1440</td><td>250</td><td>938</td><td>2000</td><td>15</td><td>30</td><td>50</td></tr><tr><td rowspan="4">Permutation</td><td>PFSP</td><td>120</td><td>100</td><td>1496</td><td>6000</td><td>5</td><td>19</td><td>60</td></tr><tr><td>SDST-PFSP</td><td>360</td><td>100</td><td>661</td><td>2000</td><td>5</td><td>12</td><td>20</td></tr><tr><td>TCT-PFSP</td><td>120</td><td>100</td><td>1496</td><td>6000</td><td>5</td><td>19</td><td>60</td></tr><tr><td>TT-PFSP</td><td>135</td><td>500</td><td>1500</td><td>2500</td><td>10</td><td>30</td><td>50</td></tr><tr><td rowspan="3">Project</td><td>RCPSP</td><td>2520</td><td>32</td><td>122</td><td>302</td><td>3</td><td>4</td><td>4</td></tr><tr><td>MMRCPSP</td><td>1080</td><td>52</td><td>77</td><td>102</td><td>4</td><td>4</td><td>4</td></tr><tr><td>RCMPSP</td><td>2254</td><td>1488</td><td>1488</td><td>1488</td><td>4</td><td>4</td><td>4</td></tr></table>

admits much more efficient scheduling models than the one used in PyJobShop, and (iii) the distributed PFSP, which is not supported even when implementing the permutation constraint.

For all machine scheduling instances, we use the best-known solutions from the data provided in Naderi et al. (2023). The best-known solutions may already be outdated since those experiments were conducted in 2021, though it is outside the scope of this paper to find all the most recent best-known solutions.

Project scheduling instances. From the project scheduling literature, we included instances from the resource-constrained project scheduling problem (RCPSP), the multi-mode project scheduling problem (MMRCPSP), and the resource-constrained multi-project scheduling problem (RCMPSP). These instances differ from machine scheduling instances because they require renewable and nonrenewable resources instead of machines.

For RCPSP, we use instances from two well-established benchmarks: PSPLIB (Kolisch and Sprecher 1997) with 30, 60, 90, and 120 tasks, and RG300 (Debels and Vanhoucke 2007) with 300 tasks. For MMRCPSP, we use instances from MMLIB (Van Peteghem and Vanhoucke 2014), specifically, the instance sets MMLIB50 and MMLIB100 containing instances with 50 and 100 tasks, respectively. For RCMPSP, we use instances from MPLIB1 (Van Eynde and Vanhoucke 2020), specifically, instance set 3, which has the largest number of tasks (1488) of the MPLIB1

dataset. The most recent best-known solutions are taken from Operations Research & Scheduling Research Group (2025) for RCPSP and MMRCPSP, and taken from Bredael and Vanhoucke (2023) for RCMPSP.

## 5.2. Computational details

Each instance was solved using eight cores of an AMD EPYC 9654 CPU with a 900-second time limit. For each instance, we compute the optimality gap and the relative percentage deviation (RPD):

$$
\mathrm {G a p} = \frac {\mathrm {U B} - \mathrm {L B}}{\mathrm {U B}} \times 1 0 0 \quad \mathrm {R P D} = \frac {\mathrm {U B} - \mathrm {B K S}}{\mathrm {B K S}} \times 1 0 0
$$

where UB and LB are the obtained upper and lower bounds, respectively, and BKS is the bestknown solution value. Note that for some TT-PFSP instances, the UB or BKS is 0. In such cases, if the numerator is nonzero, we set the corresponding metric to 100, otherwise, we set it to 0.

We used OR-Tools v9.11.4210 and CP Optimizer v22.1.1.0, each with their respective implementations of the base scheduling model presented in Section 2 but slightly modified to accommodate permutation constraints. All code, data, and results are available in our GitHub repository at https://github.com/PyJobShop/Experiments.

## 5.3. Results

Table 3 reports the average RPD and optimality gap across all problem categories by solver. Instances with infeasible solutions were excluded; however, the majority were solved feasibly, with the exception being CP Optimizer unable to find feasible solutions to 4% of the MMRCPSP instances. Overall, the results show that OR-Tools is highly competitive with CP Optimizer. It obtains comparable solutions to the JSP, FJSP, NW-PFSP, and all project scheduling variants, even obtaining slightly lower average RPDs on the FJSP, NW-PFSP, and MM-RCPSP. Additionally, OR-Tools consistently obtains better optimality gaps than CP Optimizer on all but two permutation problems, demonstrating OR-Tool's ability to compute stronger lower bounds than CP Optimizer.

CP Optimizer clearly excels in solving permutation scheduling problems, even after limiting instance sizes to accommodate OR-Tools. Expressing sequencing constraints in OR-Tools is less efficient than in CP Optimizer and this significantly impacts OR-Tools' performance on these problems. Moreover, CP Optimizer generally handles large-scale instances more efficiently. For instance, CP Optimizer outperforms OR-Tools on NPFSP and HFSP, which include instances with up to 48,000 and 2,000 tasks (and up to 10,000 modes), respectively. A notable exception is the NW-PFSP with up to 48,000 tasks, where OR-Tools unexpectedly outperforms CP Optimizer. After our benchmark, we found that CP Optimizer's performance on NW-PFSP strongly benefits

<div align="center">

Table 3 RPD and optimality gap averaged over all feasibly solved instances per problem variant, comparing OR-Tools and CP Optimizer with a 900-second time limit.

</div>

<table border="1"><tr><td rowspan="2"></td><td rowspan="2">Problem</td><td colspan="2">RPD(%)</td><td colspan="2">Gap(%)</td></tr><tr><td>OR-Tools</td><td>CP Optimizer</td><td>OR-Tools</td><td>CP Optimizer</td></tr><tr><td rowspan="6">Non-permutation</td><td>JSP</td><td>1.98</td><td>1.80</td><td>3.40</td><td>4.09</td></tr><tr><td>FJSP</td><td>0.68</td><td>0.99</td><td>1.04</td><td>27.67</td></tr><tr><td>NW-PFSP</td><td>3.47</td><td>7.18</td><td>50.51</td><td>57.87</td></tr><tr><td>NPFSP</td><td>13.53</td><td>8.88</td><td>16.29</td><td>25.58</td></tr><tr><td>HFSP</td><td>13.34</td><td>6.58</td><td>11.94</td><td>66.54</td></tr><tr><td>Average</td><td>6.60</td><td>5.08</td><td>16.63</td><td>36.35</td></tr><tr><td rowspan="5">Permutation</td><td>PFSP</td><td>7.49</td><td>2.54</td><td>10.61</td><td>7.03</td></tr><tr><td>SDST-PFSP</td><td>8.82</td><td>4.41</td><td>30.75</td><td>28.24</td></tr><tr><td>TCT-PFSP</td><td>10.31</td><td>3.31</td><td>21.48</td><td>26.69</td></tr><tr><td>TT-PFSP</td><td>53.14</td><td>20.79</td><td>66.86</td><td>72.43</td></tr><tr><td>Average</td><td>19.94</td><td>7.76</td><td>32.43</td><td>33.6</td></tr><tr><td rowspan="4">Project</td><td>RCPSP</td><td>0.93</td><td>0.46</td><td>3.46</td><td>4.26</td></tr><tr><td>MMRCPSP</td><td>0.18</td><td>0.27</td><td>0.94</td><td>6.65</td></tr><tr><td>RCMPSP</td><td>-0.52</td><td>-0.85</td><td>14.91</td><td>67.36</td></tr><tr><td>Average</td><td>0.20</td><td>-0.04</td><td>6.44</td><td>26.09</td></tr></table>

from adding permutation constraints, and based on the results in Naderi et al. (2023), it should be expected that CP Optimizer achieves an average RPD of roughly 1.6% with a 3,600-second time limit, outperforming OR-Tools (3.47%).

For project scheduling problems, OR-Tools and CP Optimizer perform similarly, with RPD differences of less than 0.5% across all variants. CP Optimizer performs better on RCPSP and RCMPSP, whereas OR-Tools achieves better results on MMRCPSP. Notably, both solvers combined found over 22,13, and 2,025 new best-known solutions to RCPSP, MMRCPSP, and RCMPSP instances, respectively, further strengthening the case of using CP for solving project scheduling problems.

## 5.4. Discussion

Our results establish OR-Tools as a strong alternative to CP Optimizer for solving various scheduling problems. This contradicts the conclusion of Naderi et al. (2023), who claim in their supplementary material (p. 9) that "OR-Tools does not yield a performance that is remotely close to that of CP Optimizer." In the main paper (footnote 7), they further suggest that OR-Tools struggles

with assignment variables compared to CP Optimizer based on their results for the FJSP and HFSP. We reviewed the OR-Tools implementation used in their experiments and found that their models for FJSP and HFSP were inefficiently formulated (see Appendix A for details). When we used their implementation on the same set of FJSP instances and identical computational setup as our numerical experiments, we obtained an average RPD of 7.55%. In contrast, our optimized OR-Tools model achieved an average RPD of just 0.68%. This suggests that the main reason for their observed inferior performance of OR-Tools was primarily due to suboptimal modeling choices, though the use of an older version of the CP-SAT solver may have also played a role.

Despite OR-Tools' strong results, CP Optimizer still holds advantages in specific areas. It scales better to larger instances and performs well on permutation-based scheduling problems, and from our personal experience, CP Optimizer often finds high-quality solutions more quickly. The advantage of CP Optimizer in large-scale instances could be attributed to its iterative diving search method, which aggressively dives into the search tree without backtracking (Laborie et al. 2018). This is also quantified by Da Col and Teppan (2022), where the authors demonstrate that CP Optimizer outperformed OR-Tools by 6-42% on job shop scheduling problems with 10,000-100,000 tasks, and successfully solved instances with up to 1,000,000 tasks where OR-Tools failed to find solutions. We remark that Da Col and Teppan (2022) only studied the performance of both solvers with at most four cores, whereas the recommended number of cores for OR-Tools is at least eight (Perron 2024).

When looking at the broader picture, both solvers demonstrate the effectiveness of CP for scheduling problems. As shown by Naderi et al. (2023), CP Optimizer generally outperforms MILP solvers in machine scheduling, and our results for CP Optimizer are largely consistent with theirs. While CP sometimes yields relatively high RPDs, such as around 10% for NPFSP, this is expected since it is compared to solutions from specialized (meta)heuristics which are fine-tuned for these problems. However, the key advantage of CP lies in its flexibility as it can easily accommodate new constraints that arise in real-world applications, whereas specialized heuristics often require extensive modifications for each new feature.

## 6. Conclusion

This paper introduced PyJobShop, an open-source Python library for solving scheduling problems with constraint programming. PyJobShop provides an easy-to-use modeling interface that allows users to solve scheduling problems without having to know the details of constraint programming. We used PyJobShop to conduct large-scale numerical experiments on a wide variety of scheduling problems from the machine scheduling and project scheduling literature. Our findings show that OR-Tools is highly competitive with CP Optimizer, particularly in job shop and project scheduling

problems, where it often matches and sometimes even surpasses CP Optimizer's performance. CP Optimizer excels in permutation scheduling problems, benefiting from its ability to handle sequencing constraints more efficiently, and large-scale scheduling problems.

For future work, we see three interesting directions. First, there are many more scheduling variants that can be supported by PyJobShop. This includes task selection problems, which include the decision whether to schedule a task or not (Kis 2003) and would support the modeling of distributed environments. Another interesting variant is multi-skilled scheduling, in which modes require skills instead of explicit resources, with resources mastering one or multiple skills (Snauwaert and Vanhoucke 2023). Second, to improve the performance of our CP solvers, it could be worthwhile to design a matheuristic that implements a metaheuristic (such as large neighborhood search) on top of constraint programming. While CP solvers already use large neighborhood search under the hood, Kasapidis et al. (2024) show that problem-specific destroy operators could further improve its performance. Finally, we plan to integrate PyJobshop with other CP solvers. MiniZinc (Stuckey et al. 2014) offers a standard modeling interface, which can be used to easily integrate a large number of CP solvers without having to write dedicated implementations as we did for OR-Tools and CP Optimizer.

To conclude, we are excited about promoting the use of constraint programming for scheduling problems and we hope that PyJobShop serves as a valuable tool for both researchers and practitioners in this pursuit.

## Acknowledgments

This work was supported by TKI Dinalog, Topsector Logistics, and the Dutch Ministry of Economic Affairs and Climate Policy.

## References

Baptiste P, Le Pape C, Nuijten W (2001) Constraint-Based Scheduling: Applying Constraint Programming to Scheduling Problems (Boston: Springer), 1st ed edition, ISBN 978-1-4613-5574-8.

Bredael D, Vanhoucke M (2023) Multi-project scheduling: A benchmark analysis of metaheuristic algorithms on various optimisation criteria and due dates. European Journal of Operational Research 308(1):54-75 ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2022.11.009.

Da Col G, Teppan EC (2022) Industrial-size job shop scheduling with constraint programming. Operations Research Perspectives 9:100249, ISSN 2214-7160, URL http://dx.doi.org/10.1016/j.orp.2022. 100249.

Dauzère-Pérès S, Ding J, Shen L, Tamssaouet K (2024) The flexible job shop scheduling problem: A review. European Journal of Operational Research 314(2):409-432, ISSN 0377-2217, URL http://dx.doi. org/10.1016/j.ejor.2023.05.017.

Debels D, Vanhoucke M (2007) A Decomposition-Based Genetic Algorithm for the Resource-Constrained Project-Scheduling Problem. Operations Research 55(3):457-469, ISSN 0030-364X, URL http://dx. doi.org/10.1287/opre.1060.0358.

Demeulemeester EL, Herroelen WS (2006) Project Scheduling: A Research Handbook (Springer Science & Business Media), ISBN 978-0-306-48142-0.

Framinan JM, Leisten R, Ruiz García R (2014) Manufacturing Scheduling Systems: An Integrated View on Models, Methods and Tools (London: Springer), 1st edition, ISBN 978-1-4471-6272-8.

Framinan JM, Perez-Gonzalez P, Fernandez-Viagas V (2019) Deterministic assembly scheduling problems: A review and classification of concurrent-type scheduling models and solution procedures. European Journal of Operational Research 273(2):401-417, ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2018.04.033.

Graham RL, Lawler EL, Lenstra JK, Kan AHGR (1979) Optimization and Approximation in Deterministic Sequencing and Scheduling: a Survey. Hammer PL, Johnson EL, Korte BH, eds., Annals of Discrete Mathematics, volume 5 of Discrete Optimization II, 287-326 (Elsevier), URL http://dx.doi.org/10. 1016/S0167-5060(08)70356-X.

Gómez Sánchez M, Lalla-Ruiz E, Fernández Gil A, Castro C, Voß S (2023) Resource-constrained multi project scheduling problem: A survey. European Journal of Operational Research 309(3):958-976, ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2022.09.033.

Hartmann S, Briskorn D (2022) An updated survey of variants and extensions of the resource-constrained project scheduling problem. European Journal of Operational Research 297(1):1-14, ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2021.05.004.

Kanet JJ, Ahire SL, Gorman MF (2004) Constraint Programming for Scheduling. Handbook of Scheduling Algorithms, Models, and Performance Analysis, 47-1-47-2 (Chapman and Hall).

Kasapidis GA, Paraskevopoulos DC, Mourtos I, Repoussis PP (2024) A unified solution framework for flexible job shop scheduling problems with multiple resource constraints. European Journal of Operational Research ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2024.08.010.

Kasapidis GA, Paraskevopoulos DC, Repoussis PP, Tarantilis CD (2021) Flexible Job Shop Scheduling Problems with Arbitrary Precedence Graphs. Production and Operations Management 30(11):40444068, ISSN 1937-5956, URL http://dx.doi.org/10.1111/poms.13501.

Kis T (2003) Job-shop scheduling with processing alternatives. European Journal of Operational Research 151(2):307-332, ISSN 0377-2217, URL http://dx.doi.org/10.1016/S0377-2217(02)00828-7.

Kolisch R, Sprecher A (1997) PSPLIB - A project scheduling problem library: OR Software - ORSEP Operations Research Software Exchange Program. European Journal of Operational Research 96(1):205-216 ISSN 0377-2217, URL http://dx.doi.org/10.1016/S0377-2217(96)00170-1.

Ku WY, Beck JC (2016) Mixed Integer Programming models for job shop scheduling: A computational analysis. Computers & Operations Research 73:165-173, ISSN 03050548, URL http://dx.doi.org/ 10.1016/j.cor.2016.04.006.

Laborie P, Rogerie J (2008) Reasoning with Conditional Time-Intervals. Wilson D, Lane HC, eds., Proceedings of the Twenty-First International Florida Artificial Intelligence Research Society Conference, May 15- 17, 2008, Coconut Grove, Florida, USA, 555-560 (AAAI Press), URL http://www.aaai.org/Library/ FLAIRS/2008/flairs08-126.php.

Laborie P, Rogerie J, Shaw P, Vilim P (2018) IBM ILOG CP optimizer for scheduling. Constraints 23(2):210250, ISSN 1572-9354, URL http://dx.doi.org/10.1007/s10601-018-9281-x.

Leung JYT, Li H, Pinedo M (2005) Order Scheduling Models: An Overview. Kendall G, Burke EK, Petrovic S, Gendreau M, eds., Multidisciplinary Scheduling: Theory and Applications, 37-53 (Boston, MA: Springer US), ISBN 978-0-387-27744-8, URL http://dx.doi.org/10.1007/0-387-27744-7_3.

MiniZinc (2025) The MiniZinc Challenge. URL https://www.minizinc.org/challenge/.

Márquez CRH, Braganholo V, Ribeiro CC (2024) An open-source framework for solving shop scheduling problems in manufacturing environments. Annals of Operations Research ISSN 1572-9338, URL http://dx.doi.org/10.1007/s10479-024-05995-6.

Naderi B, Ruiz R, Roshanaei V (2023) Mixed-Integer Programming vs. Constraint Programming for Shop Scheduling Problems: New Results and Outlook. INFORMS Journal on Computing 35(4):817-843 ISSN 1091-9856, URL http://dx.doi.org/10.1287/ijoc.2023.1287.

Operations Research & Scheduling Research Group (2025) RCPSP Solutions Update. URL http:// solutionsupdate.ugent.be/.

Ostermeier FF, Deuse J (2023) A review and classification of scheduling objectives in unpaced flow shops for discrete manufacturing. Journal of Scheduling ISSN 1099-1425, URL http://dx.doi.org/10.1007/s10951-023-00795-5.

Perron L (2024) The CP-SAT solver. URL https://www.youtube.com/watch?v=vvUxusrUcpU.

Perron L, Furnon V (2024) OR-Tools. URL https://developers.google.com/optimization/.

Pesant G (2014) A constraint programming primer. EURO Journal on Computational Optimization 2(3):8997, ISSN 2192-4414, URL http://dx.doi.org/10.1007/s13675-014-0026-3.

Pinedo M (2016) Scheduling: theory, algorithms, and systems (Springer), fifth edition, ISBN 978-3-319-26578-0, URL http://dx.doi.org/10.1007/978-3-319-26580-3.

Potts CN, Strusevich VA (2009) Fifty years of scheduling: a survey of milestones. Journal of the Operational Research Society 60(sup1):S41-S68, ISSN 0160-5682, 1476-9360, URL http://dx.doi.org/10.1057/jors.2009.2.

Reijnen R, van Straaten K, Bukhsh Z, Zhang Y (2023) Job Shop Scheduling Benchmark: Environments and Instances for Learning and Non-learning Methods. URL http://dx.doi.org/10.48550/arXiv.2308. 12794, arXiv:2308.12794 [cs].

Schutt A, Feydy T, Stuckey PJ (2013) Scheduling Optional Tasks with Explanation. Schulte C, ed., Principles and Practice of Constraint Programming, 628-644 (Berlin, Heidelberg: Springer), ISBN 978-3-642- 40627-0, URL http://dx.doi.org/10.1007/978-3-642-40627-0_47.

Snauwaert J, Vanhoucke M (2023) A classification and new benchmark instances for the multi-skilled resource-constrained project scheduling problem. European Journal of Operational Research 307(1):1- 19, ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2022.05.049.

Stuckey PJ (2010) Lazy Clause Generation: Combining the Power of SAT and CP (and MIP?) Solving. Lodi A, Milano M, Toth P, eds., Integration of AI and OR Techniques in Constraint Programming for Combinatorial Optimization Problems, 5-9 (Berlin, Heidelberg: Springer), ISBN 978-3-642-13520-0, URL http://dx.doi.org/10.1007/978-3-642-13520-0_3.

Stuckey PJ, Feydy T, Schutt A, Tack G, Fischer J (2014) The MiniZinc Challenge 2008-2013. AI Magazine 35(2):55-60, ISSN 2371-9621, URL http://dx.doi.org/10.1609/aimag.v35i2.2539.

Van Eynde R, Vanhoucke M (2020) Resource-constrained multi-project scheduling: benchmark datasets and decoupled scheduling. Journal of Scheduling 23(3):301-325, ISSN 1099-1425, URL http://dx.doi. org/10.1007/s10951-020-00651-w.

Van Peteghem V, Vanhoucke M (2014) An experimental investigation of metaheuristics for the multi-mode resource-constrained project scheduling problem on new dataset instances. European Journal of Operational Research 235(1):62-72, ISSN 0377-2217, URL http://dx.doi.org/10.1016/j.ejor.2013.10.012.

Wouda NA, Lan L, Kool W (2024) PyVRP: A High-Performance VRP Solver Package. INFORMS Journal on Computing 36(4):943-955, ISSN 1091-9856, URL http://dx.doi.org/10.1287/ijoc.2023.0055.

## Appendix A: Alternative CP model for FJSP

We describe here the OR-Tools CP model implemented by Naderi et al. (2023) for the FJSP. We use the same notation as in our main paper. In the FJSP, each job $ j\in J $ has a set of tasks $ T_{j} $ which must be processed in sequence. Let $ C^{\mathrm{EndBeforeStart}} $ define all such timing constraints between every pair of consecutive tasks i and j. The goal is to minimize the makespan. The CP model is described as follows.

$$
\min \quad \max _ {t \in T, m \in M _ {t}} \mu_ {m} ^ {\mathrm {e n d}}
$$

$$
\sum_ {m \in M _ {t}} \mu_ {m} ^ {\mathrm {p r e s e n t}} = 1
$$

$$
\forall t \in T
$$

$$
\mathrm {N o O v e r l a p} \left(\left\{\mu_ {m}: m \in M _ {r} ^ {R} \right\}\right)
$$

$$
\forall r \in R
$$

$$
\mu_ {m _ {i}} ^ {\mathrm {e n d}} \leq \mu_ {m _ {k}} ^ {\mathrm {s t a r t}}
$$

$$
\forall (i, k, l) \in C ^ {\mathrm {E n d B e f o r e S t a r t}}, m _ {i} \in M _ {i}, m _ {k} \in M _ {k}
$$

Expression (4a) minimizes the makespan objective. Constraints (4b) ensure that for each task, exactly one mode variable is selected. Constraints (4c) ensure that there is no overlap on machines. Constraints (4d)

ensure that the timing constraints between consecutive tasks are respected. In particular, Constraints (4d) are inefficient, as this defines end-before-start constraints between every pair of modes belonging to the corresponding tasks. This can be more concisely expressed using the task interval representation as described in Section 2.2, possibly resulting in better constraint propagation.
